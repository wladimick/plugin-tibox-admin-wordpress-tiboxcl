<?php
/**
 * Módulo Actividad del sitio.
 * - Carga assets/tibox-tracking.js en todo el sitio (como archivo cacheable).
 * - El script envía los eventos a GTM y, en paralelo, un resumen a
 *   /wp-json/tibox/v1/track, que se guarda agregado por día.
 * - No guarda IP, nombres, correos ni teléfonos de los visitantes.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-tbx-activity-insights.php';

final class TBX_Activity
{
    public const DB_VERSION = '1.1.0';
    public const DB_OPTION = 'tbx_activity_db_version';
    public const RETENTION_DAYS = 400;

    public const EVENTS = [
        'page_view' => 'Vistas de página',
        'phone_click' => 'Clics en teléfono',
        'email_click' => 'Clics en correo',
        'whatsapp_click' => 'Clics en WhatsApp',
        'portal_click' => 'Clics al portal cliente',
        'portal_open' => 'Aperturas del aviso del portal',
        'cta_click' => 'Clics en botones',
        'form_start' => 'Formularios iniciados',
        'form_submit' => 'Leads de landing',
        'wpforms_submit' => 'Envíos WPForms',
        'form_error' => 'Errores de formulario',
        'faq_open' => 'Preguntas frecuentes abiertas',
        'section_view' => 'Secciones vistas',
        'scroll_depth' => 'Profundidad de scroll',
        'form_success' => 'Formularios confirmados',
        'download_click' => 'Descargas de archivos',
        'outbound_click' => 'Enlaces externos',
        'engaged_15s' => 'Interacción activa (15 segundos)',
        'navigation_step' => 'Transiciones entre páginas',
        'repeated_click' => 'Clics repetidos (posible fricción)',
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tibox_activity';
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_install']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
        add_action('rest_api_init', [self::class, 'register_route']);
        add_action('tbx_activity_purge', [self::class, 'purge']);

        if (!wp_next_scheduled('tbx_activity_purge')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'tbx_activity_purge');
        }

        TBX_Admin::register_tab('actividad', 'Actividad del sitio', [self::class, 'render'], 30);
        add_filter('tbx_admin_dashboard_cards', [self::class, 'dashboard_cards'], 30);
    }

    /* ---------- Base de datos ---------- */

    public static function maybe_install(): void
    {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            TBX_Activity_Insights::install();
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day date NOT NULL,
            event varchar(40) NOT NULL DEFAULT '',
            page_path varchar(191) NOT NULL DEFAULT '',
            location varchar(60) NOT NULL DEFAULT '',
            label varchar(120) NOT NULL DEFAULT '',
            hits int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY bucket (day,event,page_path,location,label),
            KEY day_event (day,event)
        ) {$charset};");

        update_option(self::DB_OPTION, self::DB_VERSION, false);
        TBX_Activity_Insights::install();
    }

    public static function purge(): void
    {
        global $wpdb;
        $limit = wp_date('Y-m-d', strtotime('-' . self::RETENTION_DAYS . ' days'));
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s', $limit));
        TBX_Activity_Insights::purge($limit);
    }

    /* ---------- Front ---------- */

    public static function enqueue(): void
    {
        // Evitar contaminar métricas con revisiones de editores y administradores.
        // No afecta a los visitantes anónimos.
        if (is_user_logged_in()) {
            return;
        }
        $file = TBX_ADMIN_DIR . 'assets/tibox-tracking.js';
        wp_enqueue_script(
            'tbx-tracking',
            TBX_ADMIN_URL . 'assets/tibox-tracking.js',
            [],
            (string) filemtime($file),
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_add_inline_script(
            'tbx-tracking',
            'window.TBX_TRACK_URL=' . wp_json_encode(esc_url_raw(rest_url('tibox/v1/track'))) . ';',
            'before'
        );
    }

    /* ---------- Registro ---------- */

    public static function register_route(): void
    {
        register_rest_route('tibox/v1', '/track', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response
    {
        // Defensa adicional si la solicitud REST llega autenticada.
        if (is_user_logged_in()) {
            return new WP_REST_Response(['ok' => true, 'saved' => 0], 202);
        }

        /* Mismo sitio */
        $host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $origin = $request->get_header('origin') ?: $request->get_header('referer');
        if ($origin && wp_parse_url($origin, PHP_URL_HOST) !== $host) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        /* Bots */
        $ua = (string) $request->get_header('user_agent');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|headless|lighthouse|pingdom|uptime/i', $ua)) {
            return new WP_REST_Response(['ok' => true], 202);
        }

        /* Límite: 120 eventos por minuto por visitante (por IP, sin guardarla) */
        $key = 'tbx_trk_' . md5(wp_salt('nonce') . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $used = (int) get_transient($key);
        if ($used > 120) {
            return new WP_REST_Response(['ok' => false], 429);
        }

        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body) || empty($body['events']) || !is_array($body['events'])) {
            return new WP_REST_Response(['ok' => false], 400);
        }

        $path = self::clean_path((string) ($body['path'] ?? '/'));
        $events = array_slice($body['events'], 0, 40);
        $campaign = (isset($body['campaign']) && is_array($body['campaign'])) ? $body['campaign'] : [];
        set_transient($key, $used + count($events), MINUTE_IN_SECONDS);

        global $wpdb;
        $table = self::table();
        $day = wp_date('Y-m-d');
        $saved = 0;

        foreach ($events as $ev) {
            if (!is_array($ev)) {
                continue;
            }
            $event_name = $ev['e'] ?? '';
            if (!is_string($event_name)) {
                continue;
            }
            $name = sanitize_key($event_name);
            if (!isset(self::EVENTS[$name])) {
                continue;
            }
            $raw_location = isset($ev['l']) && is_string($ev['l']) ? $ev['l'] : '';
            $raw_label = isset($ev['t']) && is_string($ev['t']) ? $ev['t'] : '';
            $location = substr(sanitize_key($raw_location), 0, 60);
            $label = mb_substr(sanitize_text_field($raw_label), 0, 120);

            /* Nunca guardar correos o teléfonos completos como etiqueta */
            if (in_array($name, ['phone_click', 'email_click'], true)) {
                $label = '';
            }

            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (day, event, page_path, location, label, hits)
                 VALUES (%s, %s, %s, %s, %s, 1)
                 ON DUPLICATE KEY UPDATE hits = hits + 1",
                $day, $name, $path, $location, $label
            ));
            // Independiente de la tabla anterior: NO migra ni altera sus filas.
            TBX_Activity_Insights::record($day, $name, $path, $location, $label, $ua, $campaign);
            $saved++;
        }

        return new WP_REST_Response(['ok' => true, 'saved' => $saved], 200);
    }

    private static function clean_path(string $path): string
    {
        $path = (string) wp_parse_url($path, PHP_URL_PATH);
        $path = '/' . trim(sanitize_text_field($path), '/');
        if ($path !== '/') {
            $path .= '/';
        }
        return substr($path, 0, 191);
    }

    /* ---------- Panel ---------- */

    private static function range(): array
    {
        $days = isset($_GET['dias']) ? absint($_GET['dias']) : 30;
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;
        return [$days, wp_date('Y-m-d', strtotime('-' . ($days - 1) . ' days'))];
    }

    public static function dashboard_cards(array $cards): array
    {
        global $wpdb;
        $since = wp_date('Y-m-d', strtotime('-6 days'));
        $t = self::table();
        $views = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits),0) FROM {$t} WHERE event='page_view' AND day >= %s", $since));
        $contacts = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits),0) FROM {$t} WHERE event IN ('phone_click','email_click','whatsapp_click') AND day >= %s", $since));
        $portal = (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(hits),0) FROM {$t} WHERE event='portal_click' AND day >= %s", $since));

        $cards[] = ['value' => number_format_i18n($views), 'label' => 'Vistas de página (7 días)', 'url' => TBX_Admin::tab_url('actividad')];
        $cards[] = ['value' => number_format_i18n($contacts), 'label' => 'Clics en teléfono, correo y WhatsApp (7 días)', 'url' => TBX_Admin::tab_url('actividad')];
        $cards[] = ['value' => number_format_i18n($portal), 'label' => 'Clics al portal cliente (7 días)', 'url' => TBX_Admin::tab_url('actividad')];
        return $cards;
    }

    public static function render(): void
    {
        global $wpdb;
        [$days, $since] = self::range();
        $t = self::table();
        $raw_path = isset($_GET['ruta']) ? sanitize_text_field(wp_unslash($_GET['ruta'])) : '';
        $path_filter = $raw_path !== '' ? self::clean_path($raw_path) : '';
        $where = 'day >= %s';
        $args = [$since];
        if ($path_filter !== '') {
            $where .= ' AND page_path = %s';
            $args[] = $path_filter;
        }

        /* Selector de periodo y página */
        $paths = $wpdb->get_col($wpdb->prepare("SELECT page_path FROM {$t} WHERE day >= %s AND event='page_view' GROUP BY page_path ORDER BY SUM(hits) DESC LIMIT 60", $since));
        echo '<form method="get" class="tbx-filters">';
        echo '<input type="hidden" name="page" value="tibox-admin-actividad">';
        echo '<input type="hidden" name="dispositivo" value="' . esc_attr(isset($_GET['dispositivo']) ? sanitize_key(wp_unslash($_GET['dispositivo'])) : '') . '">';
        echo '<label>Periodo <select name="dias">';
        foreach ([7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días'] as $v => $l) {
            printf('<option value="%d"%s>%s</option>', $v, selected($days, $v, false), esc_html($l));
        }
        echo '</select></label> <label>Página <select name="ruta"><option value="">Todo el sitio</option>';
        foreach ($paths as $p) {
            printf('<option value="%1$s"%2$s>%1$s</option>', esc_attr($p), selected($path_filter, $p, false));
        }
        echo '</select></label> ';
        submit_button('Ver', 'secondary', '', false);
        echo '</form>';

        /* Totales por evento */
        $totals = $wpdb->get_results($wpdb->prepare("SELECT event, SUM(hits) AS n FROM {$t} WHERE {$where} GROUP BY event", ...$args), OBJECT_K);
        $get = fn(string $e) => isset($totals[$e]) ? (int) $totals[$e]->n : 0;

        if (!$totals) {
            echo '<div class="notice notice-info inline"><p>Aún no hay actividad registrada en este periodo. Los datos aparecen a medida que el sitio recibe visitas.</p></div>';
            return;
        }

        echo '<h2 class="tbx-h2">Resumen histórico · Todos los dispositivos</h2>';
        echo '<div class="tbx-cards">';
        foreach (['page_view', 'cta_click', 'form_start', 'form_submit', 'wpforms_submit', 'phone_click', 'email_click', 'whatsapp_click', 'portal_click', 'form_error'] as $e) {
            printf('<div class="tbx-card"><span class="tbx-card__num">%s</span><span class="tbx-card__lbl">%s</span></div>', esc_html(number_format_i18n($get($e))), esc_html(self::EVENTS[$e]));
        }
        echo '</div>';

        // Métricas nuevas segmentadas, visibles antes de las tablas históricas.
        TBX_Activity_Insights::render($since, $days, $path_filter);

        /* Embudo de landings */
        $funnel = $wpdb->get_results($wpdb->prepare(
            "SELECT page_path,
                SUM(CASE WHEN event='page_view' THEN hits ELSE 0 END) AS vistas,
                SUM(CASE WHEN event='cta_click' THEN hits ELSE 0 END) AS cta,
                SUM(CASE WHEN event='form_start' THEN hits ELSE 0 END) AS inicio,
                SUM(CASE WHEN event='form_submit' THEN hits ELSE 0 END) AS leads,
                SUM(CASE WHEN event='form_error' THEN hits ELSE 0 END) AS errores
             FROM {$t} WHERE day >= %s AND page_path LIKE %s
             GROUP BY page_path ORDER BY vistas DESC LIMIT 20",
            $since, '/lp/%'
        ));

        if ($funnel) {
            echo '<h2 class="tbx-h2">Embudo de landings</h2>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Landing</th><th class="num">Vistas</th><th class="num">Clics en botones</th><th class="num">Formulario iniciado</th><th class="num">Leads</th><th class="num">Conversión</th><th class="num">Errores</th></tr></thead><tbody>';
            foreach ($funnel as $r) {
                $conv = (int) $r->vistas > 0 ? round(((int) $r->leads / (int) $r->vistas) * 100, 1) : 0;
                printf(
                    '<tr><td><a href="%1$s">%2$s</a></td><td class="num">%3$s</td><td class="num">%4$s</td><td class="num">%5$s</td><td class="num"><strong>%6$s</strong></td><td class="num">%7$s %%</td><td class="num">%8$s</td></tr>',
                    esc_url(add_query_arg(['page' => 'tibox-admin-actividad', 'dias' => $days, 'ruta' => $r->page_path], admin_url('admin.php'))),
                    esc_html($r->page_path),
                    esc_html(number_format_i18n((int) $r->vistas)),
                    esc_html(number_format_i18n((int) $r->cta)),
                    esc_html(number_format_i18n((int) $r->inicio)),
                    esc_html(number_format_i18n((int) $r->leads)),
                    esc_html(number_format_i18n($conv, 1)),
                    esc_html(number_format_i18n((int) $r->errores))
                );
            }
            echo '</tbody></table>';
        }

        /* Botones y contactos: qué y dónde */
        $clicks = $wpdb->get_results($wpdb->prepare(
            "SELECT event, location, label, page_path, SUM(hits) AS n FROM {$t}
             WHERE {$where} AND event IN ('cta_click','phone_click','email_click','whatsapp_click','portal_click','portal_open')
             GROUP BY event, location, label, page_path ORDER BY n DESC LIMIT 40",
            ...$args
        ));
        if ($clicks) {
            echo '<h2 class="tbx-h2">Clics por botón y ubicación</h2>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Tipo</th><th>Botón o enlace</th><th>Ubicación</th><th>Página</th><th class="num">Clics</th></tr></thead><tbody>';
            foreach ($clicks as $r) {
                printf('<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td class="num">%s</td></tr>',
                    esc_html(self::EVENTS[$r->event] ?? $r->event),
                    esc_html($r->label !== '' ? $r->label : '—'),
                    esc_html($r->location !== '' ? $r->location : '—'),
                    esc_html($r->page_path),
                    esc_html(number_format_i18n((int) $r->n))
                );
            }
            echo '</tbody></table>';
        }

        /* Contenido: FAQ, secciones, scroll, errores */
        $content = $wpdb->get_results($wpdb->prepare(
            "SELECT event, label, SUM(hits) AS n FROM {$t}
             WHERE {$where} AND event IN ('faq_open','section_view','scroll_depth','form_error')
             GROUP BY event, label ORDER BY event, n DESC LIMIT 80",
            ...$args
        ));
        if ($content) {
            echo '<h2 class="tbx-h2">Contenido y formularios</h2>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Tipo</th><th>Detalle</th><th class="num">Veces</th></tr></thead><tbody>';
            foreach ($content as $r) {
                $label = $r->label;
                if ($r->event === 'scroll_depth') {
                    $label .= ' %';
                }
                printf('<tr><td>%s</td><td>%s</td><td class="num">%s</td></tr>',
                    esc_html(self::EVENTS[$r->event] ?? $r->event),
                    esc_html($label),
                    esc_html(number_format_i18n((int) $r->n))
                );
            }
            echo '</tbody></table>';
        }

        echo '<p class="tbx-muted">Resumen propio de WordPress, agregado por día y sin datos personales. Para análisis más profundo, los mismos eventos llegan a Google Analytics 4 vía GTM. Se conservan ' . (int) self::RETENTION_DAYS . ' días.</p>';
    }
}

TBX_Activity::init();
