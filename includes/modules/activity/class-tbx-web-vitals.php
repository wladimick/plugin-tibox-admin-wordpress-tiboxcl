<?php
/**
 * Agregados de Core Web Vitals del navegador.
 *
 * La API acepta valores numéricos, pero PERSISTE solamente good/improve/poor;
 * ninguna IP, user-agent, cookie, URL con query, identificador o valor individual.
 * La recopilación viene DESACTIVADA inicialmente y no altera Actividad histórica.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Web_Vitals
{
    public const OPTION = 'tbx_web_vitals_enabled';
    private const SCHEMA_OPTION = 'tbx_web_vitals_schema';
    private const SCHEMA_VERSION = '1.0.0';
    private const MAX_EVENTS = 6;
    private const METRICS = [
        'LCP' => [2500.0, 4000.0],
        'CLS' => [0.1, 0.25],
        'INP' => [200.0, 500.0],
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tbx_web_vitals';
    }

    public static function enabled(): bool
    {
        return get_option(self::OPTION, '0') === '1';
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'install']);
        add_action('rest_api_init', [self::class, 'rest_routes']);
        add_action('admin_post_tbx_save_vitals', [self::class, 'save_settings']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function install(): void
    {
        if (get_option(self::SCHEMA_OPTION) === self::SCHEMA_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day date NOT NULL,
            bucket_hash char(64) NOT NULL,
            page_path varchar(191) NOT NULL DEFAULT '',
            device varchar(12) NOT NULL DEFAULT '',
            metric varchar(3) NOT NULL DEFAULT '',
            rating varchar(8) NOT NULL DEFAULT '',
            hits int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY bucket (day,bucket_hash),
            KEY period_metric (day,metric,device)
        ) {$charset};");

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
    }

    public static function enqueue(): void
    {
        if (!self::enabled() || is_user_logged_in()) {
            return;
        }
        $file = TBX_ADMIN_DIR . 'assets/tibox-vitals.js';
        wp_enqueue_script(
            'tbx-web-vitals',
            TBX_ADMIN_URL . 'assets/tibox-vitals.js',
            [],
            (string) filemtime($file),
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_add_inline_script(
            'tbx-web-vitals',
            'window.TBX_VITALS_ENDPOINT=' . wp_json_encode(esc_url_raw(rest_url('tibox/v1/vitals'))) . ';',
            'before'
        );
    }

    public static function rest_routes(): void
    {
        register_rest_route('tibox/v1', '/vitals', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'receive'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function normalize_path($value): string
    {
        if (!is_string($value)) {
            return '/';
        }
        $path = wp_parse_url($value, PHP_URL_PATH);
        if (!is_string($path)) {
            return '/';
        }
        $path = rawurldecode($path);
        if (preg_match('/@|[\r\n]|(?:\b\d{8,}\b)/u', $path)) {
            return '/[redactado]/';
        }
        $path = preg_replace('/[^\p{L}\p{N}\/_\-.]/u', '', $path) ?? '';
        $path = '/' . trim($path, '/');
        return mb_substr($path === '/' ? '/' : $path . '/', 0, 191);
    }

    public static function rating(string $metric, float $value): ?string
    {
        if (!isset(self::METRICS[$metric]) || !is_finite($value) || $value < 0) {
            return null;
        }
        if (($metric === 'CLS' && $value > 100.0) || ($metric !== 'CLS' && $value > 600000.0)) {
            return null;
        }
        [$good, $poor] = self::METRICS[$metric];
        return $value <= $good ? 'good' : ($value <= $poor ? 'improve' : 'poor');
    }

    public static function receive(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::enabled() || is_user_logged_in()) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $origin = $request->get_header('origin') ?: $request->get_header('referer');
        if ($origin && strtolower((string) wp_parse_url($origin, PHP_URL_HOST)) !== $site_host) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $ua = (string) $request->get_header('user_agent');
        if ($ua === '' || preg_match('/bot|crawl|spider|headless|lighthouse|pingdom|monitor/i', $ua)) {
            return new WP_REST_Response(['ok' => false], 202);
        }

        // IP no se registra; únicamente llave efímera con sal para rate limiting.
        $limit_key = 'tbx_vtl_' . md5(wp_salt('nonce') . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $used = (int) get_transient($limit_key);
        if ($used >= 25) {
            return new WP_REST_Response(['ok' => false], 429);
        }
        set_transient($limit_key, $used + 1, MINUTE_IN_SECONDS);

        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body) || !isset($body['metrics']) || !is_array($body['metrics'])) {
            return new WP_REST_Response(['ok' => false], 400);
        }
        $path = self::normalize_path($body['path'] ?? '/');
        $device = TBX_Analytics_Context::classify($ua)['device'];
        $day = wp_date('Y-m-d');
        $saved = 0;
        global $wpdb;
        $table = self::table();
        $dedup = [];

        foreach (array_slice($body['metrics'], 0, self::MAX_EVENTS) as $item) {
            if (!is_array($item) || !is_string($item['name'] ?? null)) {
                continue;
            }
            $metric = strtoupper($item['name']);
            if (isset($dedup[$metric]) || !is_numeric($item['value'] ?? null)) {
                continue;
            }
            $rating = self::rating($metric, (float) $item['value']);
            if ($rating === null) {
                continue;
            }
            $dedup[$metric] = true;
            $hash = hash('sha256', wp_json_encode([$path, $device, $metric, $rating]));
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (day,bucket_hash,page_path,device,metric,rating,hits)
                 VALUES (%s,%s,%s,%s,%s,%s,1)
                 ON DUPLICATE KEY UPDATE hits=hits+1",
                $day, $hash, $path, $device, $metric, $rating
            ));
            if ($result !== false) {
                $saved++;
            }
        }
        return new WP_REST_Response(['ok' => true, 'saved' => $saved], 200);
    }

    public static function purge(string $before): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s', $before));
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Método no permitido.', '', ['response' => 405]);
        }
        check_admin_referer('tbx_save_vitals');
        update_option(self::OPTION, !empty($_POST['enable_vitals']) ? '1' : '0', false);
        wp_safe_redirect(add_query_arg('vitals_saved', '1', TBX_Admin::tab_url('actividad')));
        exit;
    }

    public static function render_settings(): void
    {
        echo '<div class="tbx-import-card"><h2>Medición de rendimiento · Core Web Vitals</h2>';
        echo '<p>Registro anónimo por página y dispositivo. La medición es opcional, utiliza la librería web-vitals de GoogleChrome y se conserva como conteos por categoría (bueno, mejorable, deficiente). No se guardan valores individuales.</p>';
        if (isset($_GET['vitals_saved'])) {
            echo '<div class="notice notice-success inline"><p>Configuración de rendimiento guardada.</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tbx_save_vitals">';
        wp_nonce_field('tbx_save_vitals');
        echo '<label><input type="checkbox" name="enable_vitals" value="1"' .
            checked(self::enabled(), true, false) . '> Activar medición LCP, CLS e INP en visitantes anónimos</label>';
        echo '<p class="tbx-muted">Desactivada por defecto. Carga la librería fijada en versión 5.3.0 desde un CDN externo solo cuando se habilita el módulo. Asegura compatibilidad con la política de consentimiento del sitio y revisa la disponibilidad del CDN antes de habilitarlo.</p>';
        submit_button('Guardar configuración', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public static function render_dashboard(string $since, string $path, string $device): void
    {
        global $wpdb;
        $table = self::table();
        $where = 'day >= %s';
        $args = [$since];
        if ($path !== '') {
            $where .= ' AND page_path = %s';
            $args[] = $path;
        }
        if ($device !== '') {
            $where .= ' AND device = %s';
            $args[] = $device;
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT metric,rating,SUM(hits) AS n FROM {$table}
             WHERE {$where} GROUP BY metric,rating ORDER BY metric,rating", ...$args
        ), ARRAY_A);
        echo '<h3 class="tbx-h2">Salud y rendimiento de páginas</h3>';
        if (!$rows) {
            echo '<p class="tbx-muted">Sin muestras LCP, CLS o INP todavía. La función está ' .
                (self::enabled() ? 'activada.' : 'desactivada.') . '</p>';
            return;
        }
        $data = [];
        foreach ($rows as $row) {
            $data[$row['metric']][$row['rating']] = (int) $row['n'];
        }
        echo '<div class="tbx-insights-grid">';
        foreach (['LCP' => 'Carga del contenido', 'CLS' => 'Estabilidad visual', 'INP' => 'Respuesta a interacciones'] as $metric => $label) {
            $x = $data[$metric] ?? [];
            $total = array_sum($x);
            $good = $total ? round(100 * ($x['good'] ?? 0) / $total, 1) : 0;
            echo '<div class="tbx-insights-group"><h3>' . esc_html($metric . ' · ' . $label) . '</h3>';
            echo '<div class="tbx-card__num">' . esc_html(number_format_i18n($good, 1)) . '%</div>';
            echo '<p class="tbx-muted">Muestras buenas: ' . esc_html(number_format_i18n((int) ($x['good'] ?? 0))) .
                ' de ' . esc_html(number_format_i18n($total)) . '</p>';
            foreach (['good' => 'Bueno', 'improve' => 'Mejorable', 'poor' => 'Deficiente'] as $rating => $name) {
                $pct = $total ? round(100 * ($x[$rating] ?? 0) / $total, 1) : 0;
                echo '<div class="tbx-insights-bar-label"><span>' . esc_html($name) .
                    '</span><strong>' . esc_html(number_format_i18n($pct, 1)) . '%</strong></div>';
                echo '<div class="tbx-insights-bar-track"><span style="width:' . esc_attr((string) $pct) . '%"></span></div>';
            }
            echo '</div>';
        }
        echo '</div>';
        echo '<p class="tbx-muted">Estas son distribuciones de muestras, no el percentil 75 que usa Google para evaluar Core Web Vitals. INP requiere interacción y podría tener menos muestras. No se envían los valores individuales ni URLs con parámetros.</p>';
    }
}

TBX_Web_Vitals::init();
