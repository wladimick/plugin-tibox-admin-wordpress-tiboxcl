<?php
/**
 * Contactos: historial importado de Tibox Leads 1.0.0.
 *
 * Durante la convivencia, replica nuevos registros desde tibox_leads_inserted.
 * Al retirar Tibox Leads, puede activar captura propia WPForms/Elementor/CF7.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Contacts
{
    public const DB_VERSION = '1.1.0';
    private const DB_OPTION = 'tbx_contacts_db_version';
    private const LEGACY_SOURCE = 'tibox-leads';
    private const BATCH_SIZE = 100;

    private const FIELDS = [
        'created_at', 'type', 'origin', 'form_id', 'form_name',
        'page_url', 'page_title', 'location', 'button_text',
        'destination', 'ref_code', 'name', 'email', 'phone',
        'message', 'fields', 'source', 'medium', 'campaign',
        'term', 'content', 'click_id', 'referrer', 'landing_page',
        'mail_status',
    ];

    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tbx_admin_contacts';
    }

    private static function legacy_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tbx_leads';
    }

    private static function exists(string $table): bool
    {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))
        ) === $table;
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'install']);
        add_action('admin_post_tbx_contacts_import', [self::class, 'import_action']);
        // Tibox Leads dispara este hook DESPUÉS de persistir su propio registro.
        add_action('tbx_leads_inserted', [self::class, 'sync_legacy_entry'], 10, 1);
        TBX_Admin::register_tab('contactos', 'Contactos', [self::class, 'render'], 25);
    }

    public static function install(): void
    {
        if (get_option(self::DB_OPTION) === self::DB_VERSION && self::exists(self::table())) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        // Fechas heredadas en GMT, como en tbx_leads; no conservar IP ni user agent.
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_plugin varchar(40) NOT NULL DEFAULT '',
            legacy_id bigint(20) unsigned NOT NULL DEFAULT 0,
            imported_at datetime NOT NULL,
            created_at datetime NOT NULL,
            type varchar(20) NOT NULL DEFAULT '',
            origin varchar(30) NOT NULL DEFAULT '',
            form_id varchar(100) NOT NULL DEFAULT '',
            form_name varchar(190) NOT NULL DEFAULT '',
            page_url varchar(500) NOT NULL DEFAULT '',
            page_title varchar(255) NOT NULL DEFAULT '',
            location varchar(40) NOT NULL DEFAULT '',
            button_text varchar(190) NOT NULL DEFAULT '',
            destination varchar(190) NOT NULL DEFAULT '',
            ref_code varchar(20) NOT NULL DEFAULT '',
            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(60) NOT NULL DEFAULT '',
            message text NULL,
            fields longtext NULL,
            source varchar(190) NOT NULL DEFAULT '',
            medium varchar(100) NOT NULL DEFAULT '',
            campaign varchar(190) NOT NULL DEFAULT '',
            term varchar(190) NOT NULL DEFAULT '',
            content varchar(190) NOT NULL DEFAULT '',
            click_id varchar(255) NOT NULL DEFAULT '',
            referrer varchar(500) NOT NULL DEFAULT '',
            landing_page varchar(500) NOT NULL DEFAULT '',
            mail_status varchar(20) NOT NULL DEFAULT '',
            device varchar(12) NOT NULL DEFAULT 'unknown',
            browser varchar(20) NOT NULL DEFAULT 'Other',
            os varchar(20) NOT NULL DEFAULT 'Other',
            PRIMARY KEY  (id),
            UNIQUE KEY source_record (source_plugin,legacy_id),
            KEY created_at (created_at),
            KEY type (type),
            KEY ref_code (ref_code)
        ) {$charset};");

        if (self::exists($table)) {
            update_option(self::DB_OPTION, self::DB_VERSION, false);
        }
    }

    private static function legacy_count(): int
    {
        global $wpdb;
        if (!self::exists(self::legacy_table())) {
            return 0;
        }
        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . self::legacy_table());
    }

    private static function pending_count(): int
    {
        global $wpdb;
        if (!self::exists(self::legacy_table()) || !self::exists(self::table())) {
            return 0;
        }
        $sql = "SELECT COUNT(*) FROM " . self::legacy_table() .
            " AS s LEFT JOIN " . self::table() .
            " AS d ON d.source_plugin = %s AND d.legacy_id = s.id WHERE d.id IS NULL";
        return (int) $wpdb->get_var($wpdb->prepare($sql, self::LEGACY_SOURCE));
    }

    private static function insert_legacy(array $row): bool
    {
        global $wpdb;
        $source_id = absint($row['id'] ?? 0);
        if ($source_id === 0) {
            return false;
        }
        // El índice UNIQUE también protege contra solicitudes simultáneas.
        $data = [
            'source_plugin' => self::LEGACY_SOURCE,
            'legacy_id' => $source_id,
            'imported_at' => current_time('mysql', true),
            // Derivado del User-Agent del plugin anterior, sin copiar el dato original.
            ...TBX_Analytics_Context::classify((string) ($row['user_agent'] ?? '')),
        ];
        foreach (self::FIELDS as $field) {
            $data[$field] = (string) ($row[$field] ?? '');
        }
        return $wpdb->insert(self::table(), $data) === 1;
    }

    public static function sync_legacy_entry(int $id): void
    {
        // La convivencia está diseñada para el plugin instalado en el mismo WP.
        if ($id <= 0 || !self::exists(self::legacy_table())) {
            return;
        }
        if (!self::exists(self::table())) {
            self::install();
        }
        if (!self::exists(self::table())) {
            return;
        }
        global $wpdb;
        $present = $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table() .
                ' WHERE source_plugin = %s AND legacy_id = %d LIMIT 1',
            self::LEGACY_SOURCE, $id
        ));
        if ($present) {
            return;
        }
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . self::legacy_table() . ' WHERE id = %d',
            $id
        ), ARRAY_A);
        if (is_array($row)) {
            self::insert_legacy($row);
        }
    }

    public static function import_action(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para importar contactos.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Método no permitido.', '', ['response' => 405]);
        }
        check_admin_referer('tbx_contacts_import');

        self::install();
        if (!self::exists(self::legacy_table()) || !self::exists(self::table())) {
            wp_safe_redirect(add_query_arg('import', 'source_missing', TBX_Admin::tab_url('contactos')));
            exit;
        }

        global $wpdb;
        // Únicamente registros ausentes, en lotes pequeños para evitar timeouts.
        $sql = $wpdb->prepare(
            'SELECT s.* FROM ' . self::legacy_table() . ' AS s LEFT JOIN ' .
                self::table() .
                ' AS d ON d.source_plugin = %s AND d.legacy_id = s.id ' .
                'WHERE d.id IS NULL ORDER BY s.id ASC LIMIT %d',
            self::LEGACY_SOURCE, self::BATCH_SIZE
        );
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $imported = 0;
        $errors = 0;
        foreach ((array) $rows as $row) {
            if (self::insert_legacy($row)) {
                $imported++;
            } else {
                $errors++;
            }
        }

        // No se elimina, vacía o modifica la tabla antigua.
        $args = ['import' => 'complete', 'imported' => $imported, 'errors' => $errors];
        wp_safe_redirect(add_query_arg($args, TBX_Admin::tab_url('contactos')));
        exit;
    }

    private static function filters(): array
    {
        global $wpdb;
        $type = isset($_GET['tipo']) ? sanitize_key(wp_unslash($_GET['tipo'])) : '';
        $allowed = ['form', 'whatsapp', 'phone', 'email'];
        if (!in_array($type, $allowed, true)) {
            $type = '';
        }
        $search = isset($_GET['s']) ? mb_substr(sanitize_text_field(wp_unslash($_GET['s'])), 0, 120) : '';
        $device = isset($_GET['dispositivo']) ? sanitize_key(wp_unslash($_GET['dispositivo'])) : '';
        if (!in_array($device, ['', 'mobile', 'desktop', 'tablet', 'unknown'], true)) {
            $device = '';
        }
        $parts = [];
        $params = [];
        if ($type !== '') {
            $parts[] = 'type = %s';
            $params[] = $type;
        }
        if ($device !== '') {
            $parts[] = 'device = %s';
            $params[] = $device;
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $parts[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR ref_code LIKE %s OR form_name LIKE %s)';
            $params = array_merge($params, array_fill(0, 5, $like));
        }
        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $params, $type, $search, $device];
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para ver los contactos.', '', ['response' => 403]);
        }
        self::install();
        global $wpdb;
        $table = self::table();
        if (!self::exists($table)) {
            echo '<div class="notice notice-error"><p>No se pudo preparar la tabla de contactos.</p></div>';
            return;
        }
        echo '<p class="tbx-lead">Registro unificado de contactos. Esta primera versión admite la importación y sincronización de TIBOX Leads 1.0.0 en el mismo WordPress.</p>';

        $legacy_found = self::exists(self::legacy_table());
        echo '<div class="tbx-import-card"><h2>Importar datos anteriores · TIBOX Leads</h2>';
        if (!$legacy_found) {
            echo '<p class="tbx-muted">No se detectó la tabla del plugin antiguo TIBOX Leads en este sitio. No se necesita importar nada si nunca se utilizó.</p>';
        } else {
            $total = self::legacy_count();
            $pending = self::pending_count();
            $copied = max(0, $total - $pending);
            printf(
                '<p>Encontrados en el plugin anterior: <strong>%1$s</strong> · Copiados: <strong>%2$s</strong> · Pendientes: <strong>%3$s</strong></p>',
                esc_html(number_format_i18n($total)),
                esc_html(number_format_i18n($copied)),
                esc_html(number_format_i18n($pending))
            );
            $by_type = $wpdb->get_results(
                'SELECT type, COUNT(*) AS n FROM ' . self::legacy_table() . ' GROUP BY type',
                ARRAY_A
            );
            if ($by_type) {
                echo '<p class="tbx-muted">';
                $labels = ['form' => 'Formularios', 'whatsapp' => 'WhatsApp', 'phone' => 'Teléfono', 'email' => 'Correo'];
                $parts = [];
                foreach ($by_type as $item) {
                    $parts[] = esc_html($labels[$item['type']] ?? $item['type']) . ': ' .
                        esc_html(number_format_i18n((int) $item['n']));
                }
                echo implode(' · ', $parts);
                echo '</p>';
            }

            $status = isset($_GET['import']) ? sanitize_key(wp_unslash($_GET['import'])) : '';
            if ($status === 'complete') {
                $done = isset($_GET['imported']) ? absint($_GET['imported']) : 0;
                $errors = isset($_GET['errors']) ? absint($_GET['errors']) : 0;
                echo '<div class="notice notice-' . ($errors ? 'warning' : 'success') . ' inline"><p>' .
                    esc_html($done . ' registros copiados; ' . $errors . ' errores. La tabla antigua no fue modificada.') .
                    '</p></div>';
            } elseif ($status === 'source_missing') {
                echo '<div class="notice notice-warning inline"><p>La tabla anterior no está disponible.</p></div>';
            }

            if ($pending > 0) {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) .
                    '" onsubmit="return confirm(&quot;¿Copiar los registros pendientes? No se modificará la tabla original.&quot;);">';
                echo '<input type="hidden" name="action" value="tbx_contacts_import">';
                wp_nonce_field('tbx_contacts_import');
                submit_button('Importar hasta ' . self::BATCH_SIZE . ' registros', 'primary', 'submit', false);
                echo '</form>';
            } else {
                echo '<p><strong>Historial sincronizado.</strong> No quedan registros pendientes en la tabla anterior.</p>';
            }
            echo '<p class="tbx-muted">La importación conserva las fechas y las referencias originales. Es segura para repetir: los IDs ya importados no se duplican. Los nuevos registros del plugin antiguo se sincronizan automáticamente mientras ambos módulos estén activos.</p>';
        }
        echo '</div>';

        TBX_Native_Contacts::render_settings();

        [$where, $params, $type, $search, $device] = self::filters();
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, absint(wp_unslash($_GET['paged']))) : 1;
        $count_sql = 'SELECT COUNT(*) FROM ' . $table . $where;
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, ...$params) : $count_sql);
        $query = $wpdb->prepare(
            'SELECT * FROM ' . $table . $where . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d',
            ...array_merge($params, [$per_page, ($page - 1) * $per_page])
        );
        $rows = $wpdb->get_results($query, ARRAY_A);

        echo '<h2 class="tbx-h2">Historial de contactos</h2>';
        echo '<form method="get" class="tbx-filters">';
        echo '<input type="hidden" name="page" value="tibox-admin-contactos">';
        echo '<label>Canal <select name="tipo">';
        $types = ['' => 'Todos', 'form' => 'Formulario', 'whatsapp' => 'WhatsApp', 'phone' => 'Teléfono', 'email' => 'Correo'];
        foreach ($types as $k => $label) {
            echo '<option value="' . esc_attr($k) . '"' . selected($type, $k, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Dispositivo <select name="dispositivo">';
        foreach (['' => 'Todos', 'mobile' => 'Móvil', 'desktop' => 'Escritorio', 'tablet' => 'Tablet', 'unknown' => 'Desconocido'] as $v => $name) {
            echo '<option value="' . esc_attr($v) . '"' . selected($device, $v, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></label>';
        echo '<label>Buscar <input name="s" type="search" value="' . esc_attr($search) .
            '" placeholder="Nombre, correo, referencia…"></label>';
        submit_button('Filtrar', 'secondary', '', false);
        echo '</form>';
        echo '<p class="tbx-muted">' . esc_html(number_format_i18n($total)) . ' registros según el filtro. Los clics representan intención de contacto, no conversaciones confirmadas.</p>';

        if (!$rows) {
            echo '<p>No hay contactos registrados con estos filtros.</p>';
            return;
        }
        echo '<div class="tbx-leads-scroll"><table class="widefat striped tbx-table tbx-leads-table"><thead><tr>';
        echo '<th>Fecha</th><th>Canal</th><th>Contacto</th><th>Dónde ocurrió</th><th>Dispositivo</th><th>Origen</th><th>Detalles</th>';
        echo '</tr></thead><tbody>';
        foreach ($rows as $r) {
            $kind = $types[$r['type']] ?? $r['type'];
            $page_path = (string) wp_parse_url($r['page_url'], PHP_URL_PATH);
            $contact = $r['type'] === 'form'
                ? ($r['name'] ?: '(sin nombre)')
                : ($r['type'] === 'whatsapp' ? 'Ref: ' . ($r['ref_code'] ?: '—') : $r['destination']);
            echo '<tr>';
            echo '<td class="tbx-leads-date">' .
                esc_html(get_date_from_gmt($r['created_at'], 'd-m-Y')) . '<small>' .
                esc_html(get_date_from_gmt($r['created_at'], 'H:i')) . '</small></td>';
            echo '<td><span class="tbx-badge ' . ($r['type'] === 'whatsapp' ? 'tbx-badge--ok' : 'tbx-badge--form') .
                '">' . esc_html($kind) . '</span></td>';
            echo '<td class="tbx-leads-contact"><strong>' . esc_html($contact) . '</strong>';
            if ($r['email'] !== '') {
                echo '<small>' . esc_html($r['email']) . '</small>';
            }
            if ($r['phone'] !== '') {
                echo '<small>' . esc_html($r['phone']) . '</small>';
            }
            echo '</td>';
            echo '<td class="tbx-leads-landing">';
            if ($r['page_url'] !== '') {
                echo '<a href="' . esc_url($r['page_url']) .
                    '" target="_blank" rel="noopener noreferrer">' . esc_html($page_path ?: $r['page_url']) . '</a>';
            }
            echo '<small>' . esc_html($r['form_name'] ?: $r['location']) . '</small></td>';
            $devices = ['mobile' => 'Móvil', 'desktop' => 'Escritorio', 'tablet' => 'Tablet', 'unknown' => 'Desconocido'];
            echo '<td>' . esc_html($devices[$r['device']] ?? 'Desconocido') . '<small style="display:block">' .
                esc_html($r['browser'] . ' · ' . $r['os']) . '</small></td>';
            echo '<td>' . esc_html($r['source'] ?: '(directo)') .
                '<small style="display:block">' . esc_html($r['medium'] ?: '(ninguno)') . '</small></td>';
            echo '<td class="tbx-leads-details"><details><summary>Ver detalles</summary><div class="tbx-leads-details-body">';
            foreach (['form_id' => 'Formulario', 'origin' => 'Tecnología', 'ref_code' => 'Referencia', 'button_text' => 'Botón',
                'destination' => 'Destino', 'message' => 'Mensaje', 'campaign' => 'Campaña',
                'term' => 'Término', 'content' => 'Contenido', 'click_id' => 'ID de anuncio',
                'referrer' => 'Referente', 'landing_page' => 'Entrada', 'mail_status' => 'Correo'] as $key => $label) {
                if ($r[$key] !== '') {
                    echo '<div><strong>' . esc_html($label) . ':</strong> ' . esc_html($r[$key]) . '</div>';
                }
            }
            $fields = json_decode((string) $r['fields'], true);
            if (is_array($fields)) {
                foreach ($fields as $field) {
                    if (!is_array($field)) {
                        continue;
                    }
                    echo '<div><strong>' . esc_html((string) ($field['l'] ?? $field['n'] ?? 'Campo')) .
                        ':</strong> ' . esc_html((string) ($field['v'] ?? '')) . '</div>';
                }
            }
            echo '</div></details></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
        $pages = (int) ceil($total / $per_page);
        if ($pages > 1) {
            $args = ['page' => 'tibox-admin-contactos', 'tipo' => $type, 's' => $search, 'dispositivo' => $device];
            $links = paginate_links([
                'base' => add_query_arg('paged', '%#%', add_query_arg($args, admin_url('admin.php'))),
                'current' => $page,
                'total' => $pages,
                'type' => 'plain',
            ]);
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post((string) $links) . '</div></div>';
        }
    }
}

require_once __DIR__ . '/class-tbx-native-capture.php';

TBX_Contacts::init();
