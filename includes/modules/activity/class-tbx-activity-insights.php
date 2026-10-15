<?php
/**
 * Analítica segmentada por contexto (desde la activación).
 * La tabla histórica tbx_activity NO se altera ni se recalcula.
 * Cada fila es un agregado diario, nunca una visita identificable.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Activity_Insights
{
    private const DB_OPTION = 'tbx_insights_db_version';
    private const DB_VERSION = '1.0.0';
    private const MEASURED = [
        'page_view', 'phone_click', 'email_click', 'whatsapp_click',
        'portal_click', 'portal_open', 'cta_click', 'form_start',
        'form_submit', 'wpforms_submit', 'form_success', 'form_error',
        'faq_open', 'section_view', 'scroll_depth',
        'download_click', 'outbound_click', 'engaged_15s',
        'navigation_step', 'repeated_click',
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tbx_activity_insights';
    }

    public static function install(): void
    {
        if (get_option(self::DB_OPTION) === self::DB_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            day date NOT NULL,
            bucket_hash char(64) NOT NULL,
            event varchar(40) NOT NULL DEFAULT '',
            page_path varchar(191) NOT NULL DEFAULT '',
            location varchar(60) NOT NULL DEFAULT '',
            label varchar(120) NOT NULL DEFAULT '',
            device varchar(12) NOT NULL DEFAULT '',
            browser varchar(20) NOT NULL DEFAULT '',
            os varchar(20) NOT NULL DEFAULT '',
            source varchar(80) NOT NULL DEFAULT '',
            medium varchar(50) NOT NULL DEFAULT '',
            campaign varchar(100) NOT NULL DEFAULT '',
            hits int(10) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY bucket (day,bucket_hash),
            KEY day_event (day,event),
            KEY day_device (day,device)
        ) {$charset};");

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t) {
            update_option(self::DB_OPTION, self::DB_VERSION, false);
        }
    }

    public static function purge(string $day): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . self::table() . ' WHERE day < %s', $day));
    }

    private static function safe_campaign($v, int $max): string
    {
        // Rechaza valores estructurados y contenido que no sean UTMs cortas.
        if (!is_scalar($v)) {
            return '';
        }
        // Solo parámetros UTM, sin URLs completas, emails ni identificadores.
        $v = sanitize_text_field((string) $v);
        if (preg_match('/@|%40|https?:|[\r\n]/i', $v)) {
            return '';
        }
        return mb_substr(preg_replace('/[^\p{L}\p{N} _.-]/u', '', $v) ?? '', 0, $max);
    }

    public static function record(string $day, string $event, string $path, string $location, string $label, string $ua, array $campaign): void
    {
        if (!in_array($event, self::MEASURED, true)) {
            return;
        }
        $c = TBX_Analytics_Context::classify($ua);
        $source = self::safe_campaign($campaign['source'] ?? '', 80);
        $medium = self::safe_campaign($campaign['medium'] ?? '', 50);
        $name = self::safe_campaign($campaign['campaign'] ?? '', 100);
        $values = [$event, $path, $location, $label, $c['device'], $c['browser'], $c['os'], $source, $medium, $name];
        $hash = hash('sha256', wp_json_encode($values));

        global $wpdb;
        $t = self::table();
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$t} (day,bucket_hash,event,page_path,location,label,device,browser,os,source,medium,campaign,hits)
             VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,1)
             ON DUPLICATE KEY UPDATE hits = hits + 1",
            $day, $hash, ...$values
        ));
    }

    private static function filter(string $since, string $path, string $device): array
    {
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
        return [$where, $args];
    }

    private static function top_dimension(string $table, string $dimension, string $where, array $args, int $total): void
    {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$dimension} AS name, SUM(hits) AS n FROM {$table}
             WHERE {$where} AND event = 'page_view' GROUP BY {$dimension}
             ORDER BY n DESC LIMIT 10", ...$args
        ));
        if (!$rows) {
            return;
        }
        $heading = ['device' => 'Dispositivos', 'browser' => 'Navegadores', 'os' => 'Sistemas operativos'][$dimension];
        $names = [
            'mobile' => 'Móvil', 'desktop' => 'Escritorio', 'tablet' => 'Tablet', 'unknown' => 'Desconocido',
        ];
        echo '<div class="tbx-insights-group"><h3>' . esc_html($heading) . '</h3>';
        foreach ($rows as $r) {
            $pct = $total > 0 ? round(100 * (int) $r->n / $total, 1) : 0;
            $label = $names[$r->name] ?? ($r->name ?: 'Desconocido');
            echo '<div class="tbx-insights-bar-label"><span>' . esc_html($label) .
                '</span><strong>' . esc_html(number_format_i18n((int) $r->n)) .
                ' (' . esc_html(number_format_i18n($pct, 1)) . '%)</strong></div>';
            echo '<div class="tbx-insights-bar-track"><span style="width:' .
                esc_attr((string) max(0, min(100, $pct))) . '%"></span></div>';
        }
        echo '</div>';
    }

    public static function render(string $since, int $days, string $path): void
    {
        global $wpdb;
        $t = self::table();
        $device = isset($_GET['dispositivo']) ? sanitize_key(wp_unslash($_GET['dispositivo'])) : '';
        if (!in_array($device, ['', 'mobile', 'desktop', 'tablet', 'unknown'], true)) {
            $device = '';
        }
        [$where, $args] = self::filter($since, $path, $device);

        echo '<section class="tbx-insights"><h2 class="tbx-h2">Dispositivos y conversiones</h2>';
        echo '<p class="tbx-muted">Datos segmentados desde que se activó esta versión; los eventos anteriores permanecen en el resumen histórico. Son eventos, no visitantes únicos.</p>';
        echo '<form class="tbx-filters" method="get">';
        echo '<input type="hidden" name="page" value="tibox-admin-actividad">';
        echo '<input type="hidden" name="dias" value="' . esc_attr((string) $days) . '">';
        echo '<input type="hidden" name="ruta" value="' . esc_attr($path) . '">';
        echo '<label>Dispositivo <select name="dispositivo">';
        foreach (['' => 'Todos', 'mobile' => 'Móvil', 'desktop' => 'Escritorio', 'tablet' => 'Tablet', 'unknown' => 'Desconocido'] as $v => $name) {
            echo '<option value="' . esc_attr($v) . '"' . selected($v, $device, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></label>';
        submit_button('Aplicar', 'secondary', '', false);
        echo '</form>';

        $totals = $wpdb->get_results($wpdb->prepare(
            "SELECT event, SUM(hits) AS n FROM {$t} WHERE {$where} GROUP BY event", ...$args
        ), OBJECT_K);
        $n = static fn(string $event): int => isset($totals[$event]) ? (int) $totals[$event]->n : 0;
        if (!$totals) {
            echo '<p class="tbx-muted">Todavía no hay actividad segmentada para esta selección.</p></section>';
            return;
        }
        $confirmed = $n('form_success') + $n('form_submit');
        $ratio = $n('form_start') > 0 ? round($confirmed * 100 / $n('form_start'), 1) : 0;
        $items = [
            ['Vistas', $n('page_view')],
            ['Clics de contacto', $n('whatsapp_click') + $n('phone_click') + $n('email_click')],
            ['Formularios iniciados', $n('form_start')],
            ['Envíos observados', $confirmed],
            ['Descargas', $n('download_click')],
            ['Enlaces externos', $n('outbound_click')],
            ['Interacción activa (aprox. min)', round($n('engaged_15s') * 15 / 60, 1)],
        ];
        echo '<div class="tbx-cards">';
        foreach ($items as [$label, $num]) {
            printf('<div class="tbx-card"><span class="tbx-card__num">%s</span><span class="tbx-card__lbl">%s</span></div>',
                esc_html(number_format_i18n($num)), esc_html($label));
        }
        echo '</div>';

        echo '<p class="tbx-muted">Ratio orientativo de envíos/inicios: <strong>' .
            esc_html(number_format_i18n($ratio, 1)) .
            '%</strong>. No corresponde a personas únicas ni demuestra abandono individual. Los envíos HTML sin confirmación pueden no contabilizarse.</p>';

        // Distribuciones calculadas exclusivamente desde page_view para no sesgar por visitantes más activos.
        $views = $n('page_view');
        echo '<div class="tbx-insights-grid">';
        foreach (['device', 'browser', 'os'] as $column) {
            self::top_dimension($t, $column, $where, $args, $views);
        }
        echo '</div>';

        // Funnel por ruta y dispositivo; no suma wpforms_submit porque también emite form_success.
        $pages = $wpdb->get_results($wpdb->prepare(
            "SELECT page_path,
             SUM(CASE WHEN event='page_view' THEN hits ELSE 0 END) AS views,
             SUM(CASE WHEN event='cta_click' THEN hits ELSE 0 END) AS cta,
             SUM(CASE WHEN event='form_start' THEN hits ELSE 0 END) AS starts,
             SUM(CASE WHEN event IN ('form_submit','form_success') THEN hits ELSE 0 END) AS sent,
             SUM(CASE WHEN event='form_error' THEN hits ELSE 0 END) AS errors,
             SUM(CASE WHEN event='whatsapp_click' THEN hits ELSE 0 END) AS whatsapp
             FROM {$t} WHERE {$where} GROUP BY page_path HAVING views > 0
             ORDER BY views DESC LIMIT 25", ...$args
        ));
        if ($pages) {
            echo '<h3 class="tbx-h2">Embudo por página</h3>';
            echo '<div class="tbx-leads-scroll"><table class="widefat striped tbx-table">';
            echo '<thead><tr><th>Página</th><th class="num">Vistas</th><th class="num">CTA</th>';
            echo '<th class="num">Inicios</th><th class="num">Envíos</th><th class="num">WhatsApp</th>';
            echo '<th class="num">Errores</th><th class="num">Envíos/inicios</th></tr></thead><tbody>';
            foreach ($pages as $p) {
                $pct = (int) $p->starts > 0 ? round((int) $p->sent * 100 / (int) $p->starts, 1) : 0;
                echo '<tr><td>' . esc_html($p->page_path) . '</td>';
                foreach (['views', 'cta', 'starts', 'sent', 'whatsapp', 'errors'] as $metric) {
                    echo '<td class="num">' . esc_html(number_format_i18n((int) $p->$metric)) . '</td>';
                }
                echo '<td class="num">' . esc_html(number_format_i18n($pct, 1)) . '%</td></tr>';
            }
            echo '</tbody></table></div>';
        }

        // Contexto publicitario: no se almacena URL completa ni identificador de visitante.
        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT source,medium,campaign,
              SUM(CASE WHEN event='page_view' THEN hits ELSE 0 END) AS views,
              SUM(CASE WHEN event IN ('form_submit','form_success') THEN hits ELSE 0 END) AS sent
             FROM {$t} WHERE {$where} AND (source != '' OR campaign != '')
             GROUP BY source,medium,campaign ORDER BY views DESC LIMIT 15", ...$args
        ));
        if ($campaigns) {
            echo '<h3 class="tbx-h2">Origen y campañas</h3>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Fuente</th><th>Medio</th><th>Campaña</th>';
            echo '<th class="num">Vistas</th><th class="num">Envíos observados</th></tr></thead><tbody>';
            foreach ($campaigns as $r) {
                echo '<tr><td>' . esc_html($r->source ?: '—') . '</td><td>' . esc_html($r->medium ?: '—') .
                    '</td><td>' . esc_html($r->campaign ?: '—') . '</td><td class="num">' .
                    esc_html(number_format_i18n((int) $r->views)) . '</td><td class="num">' .
                    esc_html(number_format_i18n((int) $r->sent)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $interactions = $wpdb->get_results($wpdb->prepare(
            "SELECT event,label,page_path,SUM(hits) AS n FROM {$t}
              WHERE {$where} AND event IN ('download_click','outbound_click')
              GROUP BY event,label,page_path ORDER BY n DESC LIMIT 20", ...$args
        ));
        if ($interactions) {
            echo '<h3 class="tbx-h2">Descargas y enlaces externos</h3>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Interacción</th><th>Elemento</th><th>Página</th><th class="num">Clics</th></tr></thead><tbody>';
            foreach ($interactions as $r) {
                echo '<tr><td>' . ($r->event === 'download_click' ? 'Descarga' : 'Enlace externo') .
                    '</td><td>' . esc_html($r->label ?: 'Sin etiqueta') . '</td><td>' .
                    esc_html($r->page_path) . '</td><td class="num">' .
                    esc_html(number_format_i18n((int) $r->n)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        // Transiciones agregadas entre rutas, sin sesión identificable.
        $routes = $wpdb->get_results($wpdb->prepare(
            "SELECT label AS origin, page_path AS destination, SUM(hits) AS n FROM {$t}
             WHERE {$where} AND event='navigation_step' AND label != ''
             GROUP BY label,page_path ORDER BY n DESC LIMIT 15", ...$args
        ));
        if ($routes) {
            echo '<h3 class="tbx-h2">Rutas frecuentes de navegación</h3>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Desde</th><th>Hacia</th><th class="num">Transiciones</th></tr></thead><tbody>';
            foreach ($routes as $r) {
                echo '<tr><td>' . esc_html($r->origin) . '</td><td>' . esc_html($r->destination) .
                    '</td><td class="num">' . esc_html(number_format_i18n((int) $r->n)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        $repeated = $wpdb->get_results($wpdb->prepare(
            "SELECT label, page_path, SUM(hits) AS n FROM {$t}
             WHERE {$where} AND event='repeated_click'
             GROUP BY label,page_path ORDER BY n DESC LIMIT 15", ...$args
        ));
        if ($repeated) {
            echo '<h3 class="tbx-h2">Posible fricción: clics repetidos</h3>';
            echo '<p class="tbx-muted">Tres clics en un control en menos de 1,7 segundos. Es una señal orientativa, no confirma un error.</p>';
            echo '<table class="widefat striped tbx-table"><thead><tr><th>Control</th><th>Página</th><th class="num">Veces</th></tr></thead><tbody>';
            foreach ($repeated as $r) {
                echo '<tr><td>' . esc_html($r->label ?: 'Control') . '</td><td>' .
                    esc_html($r->page_path) . '</td><td class="num">' .
                    esc_html(number_format_i18n((int) $r->n)) . '</td></tr>';
            }
            echo '</tbody></table>';
        }
        echo '</section>';
    }
}
