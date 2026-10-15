<?php
/**
 * Fuentes de Contactos: landings de TIBOX Admin y entradas WPForms.
 *
 * Importar es opcional y no destructivo. Las tablas fuente nunca se alteran;
 * se usan IDs originales y una restricción UNIQUE fuente+ID.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Contact_Sources
{
    private const LIMIT = 100;
    private const LANDINGS = 'tibox-landing';
    private const WPFORMS = 'wpforms-history';

    private static function table(string $source): string
    {
        global $wpdb;
        return $source === self::LANDINGS
            ? $wpdb->prefix . 'tibox_landing_leads'
            : $wpdb->prefix . 'wpforms_entries';
    }

    private static function available(string $source): bool
    {
        global $wpdb;
        $table = self::table($source);
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
    }

    public static function init(): void
    {
        add_action('admin_post_tbx_contacts_import_source', [self::class, 'import_action']);
        // Si las landings generan nuevos envíos, la misma bandeja los captura,
        // incluso cuando el módulo Leads utiliza su propio almacenamiento.
        add_action('tibox_landing_lead_created', [self::class, 'landing_created'], 20, 1);
    }

    private static function source_option($value): string
    {
        return in_array($value, [self::LANDINGS, self::WPFORMS], true) ? $value : '';
    }

    private static function already_seen(string $source, int $id): bool
    {
        global $wpdb;
        if (TBX_Contacts::is_suppressed($source, $id)) {
            return true;
        }
        $sql = 'SELECT id FROM ' . TBX_Contacts::table() .
            ' WHERE source_plugin = %s AND legacy_id = %d LIMIT 1';
        return (bool) $wpdb->get_var($wpdb->prepare($sql, $source, $id));
    }

    private static function strip_url($value): string
    {
        $url = is_string($value) ? $value : '';
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '' || strtolower($host) !== strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST))) {
            return '';
        }
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        if (preg_match('/@|[\\r\\n]/u', $path)) {
            return '';
        }
        return home_url('/' . trim($path, '/'));
    }

    private static function safe_text($value, int $len): string
    {
        return is_scalar($value) ? mb_substr(sanitize_text_field((string) $value), 0, $len) : '';
    }

    private static function safe_long($value, int $len): string
    {
        return is_scalar($value) ? mb_substr(sanitize_textarea_field((string) $value), 0, $len) : '';
    }

    private static function write(string $source, int $id, array $data): bool
    {
        if ($id < 1 || self::already_seen($source, $id)) {
            return false;
        }
        global $wpdb;
        $defaults = [
            'source_plugin' => $source, 'legacy_id' => $id,
            'imported_at' => current_time('mysql', true),
            'created_at' => current_time('mysql', true),
            'type' => 'form', 'status' => 'nuevo',
        ];
        return $wpdb->insert(TBX_Contacts::table(), array_merge($defaults, $data)) === 1;
    }

    public static function landing_created($record): void
    {
        if (!is_array($record) || !self::available(self::LANDINGS)) {
            return;
        }
        $id = absint($record['lead_id'] ?? 0);
        if ($id > 0) {
            self::import_one(self::LANDINGS, $id);
        }
    }

    private static function wpforms_fields($json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }
        $fields = [];
        foreach (array_slice($decoded, 0, 100) as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::safe_text($item['name'] ?? ('Campo ' . $key), 100);
            $type = self::safe_text($item['type'] ?? '', 30);
            $value = $item['value'] ?? '';
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, 'is_scalar'));
            }
            $fields[] = [
                'n' => 'field_' . self::safe_text($item['id'] ?? $key, 40),
                'l' => $name,
                't' => $type,
                'v' => $value,
            ];
        }
        // Reutilizar la misma validación que aplica la captura nativa.
        return TBX_Native_Contacts::normalize_fields($fields);
    }

    private static function import_one(string $source, int $id): bool
    {
        if (!self::available($source) || self::already_seen($source, $id)) {
            return false;
        }
        global $wpdb;
        $r = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table($source) . ' WHERE ' .
                ($source === self::WPFORMS ? 'entry_id' : 'id') . ' = %d', $id),
            ARRAY_A
        );
        if (!is_array($r)) {
            return false;
        }
        // Entradas automáticas, incompletas o marcadas como spam de WPForms
        // nunca deben figurar como consultas comerciales confirmadas.
        if ($source === self::WPFORMS &&
            ((string) ($r['type'] ?? '') !== 'entry' ||
             in_array((string) ($r['status'] ?? ''), ['spam', 'trash'], true))) {
            return false;
        }
        if ($source === self::LANDINGS) {
            $date = self::safe_text($r['created_at'] ?? '', 25);
            $data = [
                // Las landings guardan fecha local de WordPress, a diferencia de tbx_leads.
                'created_at' => $date ? get_gmt_from_date($date) : current_time('mysql', true),
                'origin' => 'landing_ai',
                'form_id' => self::safe_text($r['form_id'] ?? '', 100),
                'form_name' => 'Landing · ' . self::safe_text($r['page_title'] ?? '', 160),
                'page_url' => self::strip_url($r['landing_url'] ?? ''),
                'page_title' => self::safe_text($r['page_title'] ?? '', 255),
                'name' => self::safe_text($r['name'] ?? '', 190),
                'email' => sanitize_email(self::safe_text($r['email'] ?? '', 190)),
                'phone' => self::safe_text($r['phone'] ?? '', 60),
                'message' => self::safe_long($r['message'] ?? '', 5000),
                'source' => self::safe_text($r['utm_source'] ?? '', 190),
                'medium' => self::safe_text($r['utm_medium'] ?? '', 100),
                'campaign' => self::safe_text($r['utm_campaign'] ?? '', 190),
                'term' => self::safe_text($r['utm_term'] ?? '', 190),
                'content' => self::safe_text($r['utm_content'] ?? '', 190),
                'click_id' => self::safe_text($r['gclid'] ?? '', 255),
                'fields' => wp_json_encode([
                    ['n' => 'company', 'l' => 'Empresa', 'v' => self::safe_text($r['company'] ?? '', 190)],
                    ['n' => 'rut', 'l' => 'RUT empresa', 'v' => self::safe_text($r['rut'] ?? '', 40)],
                    ['n' => 'area', 'l' => 'Área', 'v' => self::safe_text($r['area'] ?? '', 190)],
                    ['n' => 'users', 'l' => 'Usuarios', 'v' => self::safe_text($r['users'] ?? '', 80)],
                ]),
            ];
            return self::write($source, $id, $data);
        }

        $fields = self::wpforms_fields($r['fields'] ?? '');
        if (!$fields) {
            return false;
        }
        $contact = TBX_Native_Contacts::extract_contact($fields);
        $form_id = absint($r['form_id'] ?? 0);
        $date = self::safe_text($r['date'] ?? '', 25);
        $data = [
            'created_at' => $date ?: current_time('mysql', true),
            'origin' => 'wpforms',
            'form_id' => (string) $form_id,
            'form_name' => $form_id ? self::safe_text(get_the_title($form_id), 190) : 'WPForms',
            'name' => $contact['name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'],
            'message' => $contact['message'],
            'fields' => wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
        ];

        // Evitar duplicar el mismo envío de Prodata, ya capturado por TIBOX Leads:
        // coincidencia de correo, tecnología y margen de 2 minutos.
        // Si no hay email, no inferir duplicado para no perder consultas legítimas.
        if ($contact['email'] !== '') {
            $dupe = $wpdb->get_var($wpdb->prepare(
                'SELECT id FROM ' . TBX_Contacts::table() .
                ' WHERE source_plugin IN (%s,%s) AND type = %s AND email = %s ' .
                'AND created_at BETWEEN %s AND %s LIMIT 1',
                'tibox-leads', 'tibox-admin-native', 'form', $contact['email'],
                gmdate('Y-m-d H:i:s', strtotime($date . ' UTC') - 120),
                gmdate('Y-m-d H:i:s', strtotime($date . ' UTC') + 120)
            ));
            if ($dupe) {
                // Marca la entrada propia de WPForms como cubierta por el historial anterior;
                // sin destruir ninguna fila fuente ni copiar dos veces los mismos datos.
                return TBX_Contacts::suppress_external($source, $id);
            }
        }
        return self::write($source, $id, $data);
    }

    private static function count_pending(string $source): int
    {
        global $wpdb;
        if (!self::available($source)) {
            return 0;
        }
        $src = self::table($source);
        $pk = $source === self::WPFORMS ? 'entry_id' : 'id';
        $valid = $source === self::WPFORMS
            ? " AND s.type = 'entry' AND s.status NOT IN ('spam','trash')" : '';
        $sql = 'SELECT COUNT(*) FROM ' . $src . ' s LEFT JOIN ' . TBX_Contacts::table() .
            ' c ON c.source_plugin = %s AND c.legacy_id = s.' . $pk .
            ' LEFT JOIN ' . TBX_Contacts::suppressed_table() .
            ' d ON d.source_plugin = %s AND d.legacy_id = s.' . $pk .
            ' WHERE c.id IS NULL AND d.legacy_id IS NULL' . $valid;
        return (int) $wpdb->get_var($wpdb->prepare($sql, $source, $source));
    }

    public static function render(): void
    {
        echo '<details class="tbx-contact-source-tools"><summary>Importar formularios de otras fuentes (opcional)</summary>';
        echo '<p class="tbx-muted">Esta operación copia el histórico que aún no está en Contactos. No modifica WPForms ni el registro original de las landings.</p>';
        foreach ([self::LANDINGS => 'Landings de TIBOX', self::WPFORMS => 'WPForms (historial guardado)'] as $source => $label) {
            if (!self::available($source)) {
                echo '<p><strong>' . esc_html($label) . ':</strong> no se detectó su tabla de entradas en este WordPress.</p>';
                continue;
            }
            $pending = self::count_pending($source);
            echo '<div class="tbx-contact-source"><strong>' . esc_html($label) . '</strong> · ' .
                esc_html(number_format_i18n($pending)) . ' entradas pendientes de revisar o importar.';
            if ($pending > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) .
                    '" onsubmit="return confirm(&quot;¿Importar hasta 100 registros de esta fuente? El original permanece intacto.&quot;)">';
                echo '<input type="hidden" name="action" value="tbx_contacts_import_source">';
                echo '<input type="hidden" name="source" value="' . esc_attr($source) . '">';
                wp_nonce_field('tbx_contacts_import_source_' . $source);
                submit_button('Importar hasta 100', 'secondary', 'submit', false);
                echo '</form>';
            }
            echo '</div>';
        }
        echo '<p class="tbx-muted">WPForms Lite o instalaciones que no almacenan entradas en WordPress no tienen un historial recuperable desde esta opción. Un envío ya registrado por TIBOX Leads puede quedar identificado como duplicado y omitirse.</p>';
        echo '</details>';
    }

    public static function import_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Método no permitido.', '', ['response' => 405]);
        }
        $source = self::source_option(isset($_POST['source']) && is_string($_POST['source'])
            ? sanitize_key(wp_unslash($_POST['source'])) : '');
        if ($source === '') {
            wp_die('Fuente no permitida.', '', ['response' => 400]);
        }
        check_admin_referer('tbx_contacts_import_source_' . $source);
        TBX_Contacts::install();
        if (!self::available($source)) {
            wp_die('No existe la tabla de esa fuente.', '', ['response' => 404]);
        }
        global $wpdb;
        $pk = $source === self::WPFORMS ? 'entry_id' : 'id';
        $valid = $source === self::WPFORMS
            ? " AND s.type = 'entry' AND s.status NOT IN ('spam','trash')" : '';
        $sql = 'SELECT s.' . $pk . ' FROM ' . self::table($source) .
            ' s LEFT JOIN ' . TBX_Contacts::table() .
            ' c ON c.source_plugin = %s AND c.legacy_id = s.' . $pk .
            ' LEFT JOIN ' . TBX_Contacts::suppressed_table() .
            ' d ON d.source_plugin = %s AND d.legacy_id = s.' . $pk .
            ' WHERE c.id IS NULL AND d.legacy_id IS NULL' . $valid .
            ' ORDER BY s.' . $pk . ' ASC LIMIT %d';
        $ids = $wpdb->get_col($wpdb->prepare($sql, $source, $source, self::LIMIT));
        $done = 0;
        $failed = 0;
        foreach ((array) $ids as $id) {
            if (self::import_one($source, absint($id))) {
                $done++;
            } else {
                $failed++;
            }
        }
        wp_safe_redirect(add_query_arg([
            'source_imported' => $done, 'source_errors' => $failed,
            'source_name' => $source,
        ], TBX_Admin::tab_url('contactos')));
        exit;
    }
}

TBX_Contact_Sources::init();
