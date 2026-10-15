<?php
/* Portado desde el snippet «TIBOX — Endpoint universal de formularios» v2. Tibox Admin 1.0.0 */
/**
 * TIBOX — Endpoint universal de formularios + almacenamiento local de Leads
 *
 * Endpoint:
 * POST /wp-json/tibox/v1/lead
 *
 * Funciones:
 * - Valida origen, honeypot, rate limit, email, RUT y consentimiento.
 * - Guarda cada lead en una tabla propia de WordPress.
 * - Relaciona el lead con la Landing correspondiente.
 * - Añade un metabox "Leads recibidos" dentro de cada Landing.
 * - Envía correo interno y confirmación al contacto.
 * - Conserva UTM, GCLID, GBRAID y WBRAID.
 * - Dispara el hook tibox_landing_lead_created para WebOps/CRM.
 * - Usa submission_id para evitar duplicados.
 *
 * WPCode: pegar como snippet PHP SIN agregar <?php.
 *
 * v2 (15-10-2026):
 * - Destinatarios: Para contacto@tibox.cl, con copia a jmoyano, pfarias,
 *   flizana y wdiaz (pedido de Paula Farías).
 * - Correo interno con formato tipo WPForms (campo en negrita, valor abajo).
 * - Asunto con el mismo patrón que los formularios WPForms comerciales.
 * - Correo de confirmación al contacto rediseñado: qué sigue y resumen.
 * - Remitente fijo (FROM_EMAIL) igual al que ya usan los formularios WPForms.
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('TIBOX_Landing_Form_API', false)) {

final class TIBOX_Landing_Form_API
{
    private const REST_NAMESPACE = 'tibox/v1';
    private const REST_ROUTE = '/lead';
    private const LANDING_POST_TYPE = 'tibox_landing';

    private const DB_VERSION = '1.1.0';
    private const DB_VERSION_OPTION = 'tibox_landing_leads_db_version';

    /* Valores por defecto. Se editan en Tibox Admin › Formularios y correos. */
    public const SETTINGS_OPTION = 'tbx_admin_lead_form_settings';

    public static function defaults(): array
    {
        return [
            'to' => 'contacto@tibox.cl',
            'cc' => "jmoyano@tibox.cl\npfarias@tibox.cl\nflizana@tibox.cl\nwdiaz@tibox.cl",
            'from_email' => 'diseno@tibox.cl',
            'from_name' => 'TIBOX',
            'reply_to' => 'contacto@tibox.cl',
            'confirmation' => 1,
            'webops' => 1,
        ];
    }

    public static function setting(string $key)
    {
        // El plugin Prodata también usa "tbx_leads_settings", pero con otro
        // esquema. No sobrescribir sus ajustes; leer únicamente el legado
        // de Tibox Admin mientras el usuario no guarde en el nuevo nombre.
        $saved = get_option(self::SETTINGS_OPTION, null);
        if (!is_array($saved)) {
            $legacy = get_option('tbx_leads_settings', []);
            $saved = is_array($legacy) && array_key_exists('to', $legacy) ? $legacy : [];
        }
        $all = array_merge(self::defaults(), $saved);
        return $all[$key] ?? null;
    }

    public static function cc_list(): array
    {
        $raw = (string) self::setting('cc');
        $list = preg_split('/[\s,;]+/', $raw) ?: [];
        return array_values(array_filter(array_map('sanitize_email', $list), 'is_email'));
    }

    private const RATE_LIMIT_SECONDS = 60;
    private const MAX_REQUESTS_PER_WINDOW = 5;

    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_install_table']);
        add_action('rest_api_init', [self::class, 'register_route']);
        add_action('add_meta_boxes', [self::class, 'add_leads_metabox']);

        /* La pantalla de leads vive en Tibox Admin › Leads */
    }

    /* =========================================================
     * BASE DE DATOS
     * ========================================================= */

    private static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tibox_landing_leads';
    }

    public static function maybe_install_table(): void
    {
        if (
            get_option(self::DB_VERSION_OPTION) === self::DB_VERSION &&
            self::table_exists()
        ) {
            return;
        }

        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_id varchar(64) NOT NULL,
            landing_id bigint(20) unsigned NOT NULL DEFAULT 0,
            form_id varchar(190) NOT NULL DEFAULT '',
            source_key varchar(80) NOT NULL DEFAULT 'landing_ai',
            created_at datetime NOT NULL,

            name varchar(190) NOT NULL DEFAULT '',
            email varchar(190) NOT NULL DEFAULT '',
            phone varchar(80) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            rut varchar(40) NOT NULL DEFAULT '',
            area varchar(190) NOT NULL DEFAULT '',
            users varchar(80) NOT NULL DEFAULT '',
            message text NULL,
            privacy tinyint(1) unsigned NOT NULL DEFAULT 0,

            landing_url text NULL,
            landing_path varchar(255) NOT NULL DEFAULT '',
            page_title text NULL,

            utm_source varchar(190) NOT NULL DEFAULT '',
            utm_medium varchar(190) NOT NULL DEFAULT '',
            utm_campaign varchar(190) NOT NULL DEFAULT '',
            utm_term varchar(255) NOT NULL DEFAULT '',
            utm_content varchar(255) NOT NULL DEFAULT '',

            gclid varchar(255) NOT NULL DEFAULT '',
            gbraid varchar(255) NOT NULL DEFAULT '',
            wbraid varchar(255) NOT NULL DEFAULT '',

            PRIMARY KEY  (id),
            UNIQUE KEY submission_id (submission_id),
            KEY landing_id (landing_id),
            KEY email (email),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::DB_VERSION_OPTION, self::DB_VERSION, false);
    }

    private static function table_exists(): bool
    {
        global $wpdb;

        $table = self::table_name();

        $found = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($table)
            )
        );

        return $found === $table;
    }

    /* =========================================================
     * REST API
     * ========================================================= */

    public static function register_route(): void
    {
        register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'handle_request'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function handle_request(
        WP_REST_Request $request
    ): WP_REST_Response {
        if (!self::is_same_site_request($request)) {
            return self::response(
                false,
                false,
                'No fue posible validar el origen de la solicitud.',
                403
            );
        }

        if (!self::check_rate_limit()) {
            return self::response(
                false,
                false,
                'Has realizado demasiados intentos. Espera un momento.',
                429
            );
        }

        $data = self::get_request_data($request);

        /*
         * Honeypot: respondemos éxito para no avisar al bot,
         * pero lead_created=false evita disparar form_submit/Ads.
         */
        if ($data['website'] !== '') {
            return self::response(
                true,
                false,
                'Recibimos tu solicitud.',
                200,
                $data['submission_id']
            );
        }

        $errors = self::validate($data);

        if ($errors !== []) {
            return new WP_REST_Response([
                'success' => false,
                'lead_created' => false,
                'message' => 'Revisa los campos destacados.',
                'errors' => $errors,
                'submission_id' => $data['submission_id'],
            ], 422);
        }

        $data['landing_id'] = self::resolve_landing_id(
            $data['landing_url'],
            $data['landing_path']
        );

        /*
         * El almacenamiento local es la fuente mínima de verdad.
         * Si no podemos guardar el lead, no informamos conversión.
         */
        $lead_id = self::store_lead($data);

        if ($lead_id <= 0) {
            error_log(
                '[TIBOX Landing] No fue posible guardar el lead localmente. ' .
                'Submission: ' . $data['submission_id']
            );

            return self::response(
                false,
                false,
                'No pudimos registrar tu solicitud. Intenta nuevamente.',
                500,
                $data['submission_id']
            );
        }

        $data['lead_id'] = $lead_id;

        /*
         * Desde este punto el lead ya existe en WordPress.
         * Los fallos de email o integraciones externas no deben
         * convertir una solicitud válida en un lead perdido.
         */
        self::send_internal_email($data);
        self::send_confirmation_email($data);

        /*
         * WebOps / CRM / otras integraciones.
         * El bridge existente puede seguir escuchando este hook.
         */
        do_action('tibox_landing_lead_created', $data);

        return self::response(
            true,
            true,
            'Solicitud recibida. Nuestro equipo gestionará tu requerimiento y te contactará dentro del próximo día hábil.',
            200,
            $data['submission_id'],
            $lead_id
        );
    }

    private static function get_request_data(
        WP_REST_Request $request
    ): array {
        $params = $request->get_json_params();

        if (!is_array($params) || $params === []) {
            $params = $request->get_body_params();
        }

        $submission_id = sanitize_text_field(
            $params['submission_id'] ?? ''
        );

        if ($submission_id === '') {
            $submission_id = wp_generate_uuid4();
        }

        return [
            'submission_id' => $submission_id,
            'form_id' => sanitize_key(
                $params['form_id'] ?? 'tibox-lead-form'
            ),
            /* Fuente controlada por servidor; no confiar en el navegador. */
            'source_key' => 'landing_ai',

            'name' => sanitize_text_field($params['name'] ?? ''),
            'email' => sanitize_email($params['email'] ?? ''),
            'phone' => sanitize_text_field($params['phone'] ?? ''),
            'company' => sanitize_text_field($params['company'] ?? ''),
            'rut' => sanitize_text_field($params['rut'] ?? ''),
            'area' => sanitize_text_field($params['area'] ?? ''),
            'users' => sanitize_text_field($params['users'] ?? ''),
            'message' => sanitize_textarea_field($params['message'] ?? ''),
            'privacy' => self::to_boolean($params['privacy'] ?? false),

            /* Honeypot */
            'website' => sanitize_text_field($params['website'] ?? ''),

            /* Contexto */
            'landing_url' => esc_url_raw($params['landing_url'] ?? ''),
            'landing_path' => sanitize_text_field(
                $params['landing_path'] ?? ''
            ),
            'page_title' => sanitize_text_field($params['page_title'] ?? ''),

            /* Atribución */
            'utm_source' => sanitize_text_field($params['utm_source'] ?? ''),
            'utm_medium' => sanitize_text_field($params['utm_medium'] ?? ''),
            'utm_campaign' => sanitize_text_field(
                $params['utm_campaign'] ?? ''
            ),
            'utm_term' => sanitize_text_field($params['utm_term'] ?? ''),
            'utm_content' => sanitize_text_field(
                $params['utm_content'] ?? ''
            ),
            'gclid' => sanitize_text_field($params['gclid'] ?? ''),
            'gbraid' => sanitize_text_field($params['gbraid'] ?? ''),
            'wbraid' => sanitize_text_field($params['wbraid'] ?? ''),
        ];
    }

    private static function validate(array $data): array
    {
        $errors = [];

        if (mb_strlen($data['name']) < 2) {
            $errors['name'] = 'Ingresa tu nombre.';
        }

        if (!is_email($data['email'])) {
            $errors['email'] = 'Ingresa un correo válido.';
        }

        if (mb_strlen($data['company']) < 2) {
            $errors['company'] = 'Ingresa el nombre de la empresa.';
        }

        if (!self::validate_chilean_rut($data['rut'])) {
            $errors['rut'] = 'Ingresa un RUT empresa válido.';
        }

        if (!$data['privacy']) {
            $errors['privacy'] = 'Debes aceptar el Aviso de Privacidad.';
        }

        if (
            $data['landing_url'] !== '' &&
            wp_parse_url($data['landing_url'], PHP_URL_HOST) !==
                wp_parse_url(home_url('/'), PHP_URL_HOST)
        ) {
            $errors['landing_url'] = 'La URL de origen no es válida.';
        }

        return $errors;
    }

    /* =========================================================
     * ALMACENAMIENTO LOCAL
     * ========================================================= */

    private static function resolve_landing_id(
        string $landing_url,
        string $landing_path
    ): int {
        if ($landing_url !== '') {
            $candidate = url_to_postid($landing_url);

            if (
                $candidate > 0 &&
                get_post_type($candidate) === self::LANDING_POST_TYPE
            ) {
                return (int) $candidate;
            }
        }

        $path = $landing_path;

        if ($path === '' && $landing_url !== '') {
            $path = (string) wp_parse_url($landing_url, PHP_URL_PATH);
        }

        $path = trim($path, '/');

        if ($path === '') {
            return 0;
        }

        $parts = array_values(array_filter(explode('/', $path)));
        $slug = end($parts);

        if (!is_string($slug) || $slug === '') {
            return 0;
        }

        $landing = get_page_by_path(
            sanitize_title($slug),
            OBJECT,
            self::LANDING_POST_TYPE
        );

        return $landing instanceof WP_Post
            ? (int) $landing->ID
            : 0;
    }

    private static function store_lead(array $data): int
    {
        global $wpdb;

        $table = self::table_name();

        /* Idempotencia */
        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE submission_id = %s LIMIT 1",
                $data['submission_id']
            )
        );

        if ($existing_id !== null) {
            return (int) $existing_id;
        }

        $inserted = $wpdb->insert(
            $table,
            [
                'submission_id' => $data['submission_id'],
                'landing_id' => (int) ($data['landing_id'] ?? 0),
                'form_id' => $data['form_id'],
                'source_key' => $data['source_key'],
                'created_at' => current_time('mysql'),

                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'],
                'company' => $data['company'],
                'rut' => $data['rut'],
                'area' => $data['area'],
                'users' => $data['users'],
                'message' => $data['message'],
                'privacy' => $data['privacy'] ? 1 : 0,

                'landing_url' => $data['landing_url'],
                'landing_path' => $data['landing_path'],
                'page_title' => $data['page_title'],

                'utm_source' => $data['utm_source'],
                'utm_medium' => $data['utm_medium'],
                'utm_campaign' => $data['utm_campaign'],
                'utm_term' => $data['utm_term'],
                'utm_content' => $data['utm_content'],

                'gclid' => $data['gclid'],
                'gbraid' => $data['gbraid'],
                'wbraid' => $data['wbraid'],
            ],
            [
                '%s', '%d', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d',
                '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s',
            ]
        );

        if ($inserted === false) {
            error_log(
                '[TIBOX Landing] Error DB: ' . $wpdb->last_error
            );
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /* =========================================================
     * ADMIN: LANDINGS → LEADS
     * ========================================================= */

    public static function add_leads_submenu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . self::LANDING_POST_TYPE,
            'Leads de Landings',
            'Leads',
            'manage_options',
            'tibox-landing-leads',
            [self::class, 'render_leads_admin_page']
        );
    }

    /**
     * Filtros reutilizados por listado y CSV. Solo se aceptan columnas conocidas.
     * @return array{0:string,1:array,2:int,3:string,4:string}
     */
    public static function admin_filter_sql(): array
    {
        global $wpdb;
        $landing = isset($_GET['landing_id']) ? absint(wp_unslash($_GET['landing_id'])) : 0;
        $month = isset($_GET['mes']) ? sanitize_text_field(wp_unslash($_GET['mes'])) : '';
        $search = isset($_GET['buscar']) ? sanitize_text_field(wp_unslash($_GET['buscar'])) : '';
        $search = mb_substr($search, 0, 120);

        $parts = [];
        $args = [];
        if ($landing > 0) {
            $parts[] = 'landing_id = %d';
            $args[] = $landing;
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $parts[] = 'created_at >= %s AND created_at < %s';
            $args[] = $month . '-01 00:00:00';
            $args[] = gmdate('Y-m-d 00:00:00', strtotime($month . '-01 +1 month'));
        } else {
            $month = '';
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $parts[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s OR rut LIKE %s)';
            for ($i = 0; $i < 5; $i++) {
                $args[] = $like;
            }
        }

        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $args, $landing, $month, $search];
    }

    public static function render_leads_admin_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para ver esta página.'));
        }

        self::maybe_install_table();

        if (isset($_GET['tbx_deleted'])) {
            $deleted = sanitize_key(wp_unslash($_GET['tbx_deleted']));
            if ($deleted === '1') {
                echo '<div class="notice notice-success is-dismissible"><p>Lead eliminado correctamente.</p></div>';
            } elseif ($deleted === '0') {
                echo '<div class="notice notice-error is-dismissible"><p>No se pudo eliminar el lead. Puede que ya no exista.</p></div>';
            }
        }

        global $wpdb;

        $table = self::table_name();

        if (!self::table_exists()) {
            echo '<div class="tbx-panel">';
            echo '<div class="notice notice-error"><p>';
            echo 'No fue posible crear o localizar la tabla de leads: <code>' .
                esc_html($table) .
                '</code>. Revisa el log de WordPress/servidor.';
            echo '</p></div></div>';
            return;
        }

        $per_page = 50;
        $page = isset($_GET['paged'])
            ? max(1, absint($_GET['paged']))
            : 1;
        $offset = ($page - 1) * $per_page;

        [$where, $query_args, $landing_filter, $month_filter, $search_filter] = self::admin_filter_sql();
        $total_sql = $query_args
            ? $wpdb->prepare("SELECT COUNT(*) FROM {$table}{$where}", ...$query_args)
            : "SELECT COUNT(*) FROM {$table}";
        $total = (int) $wpdb->get_var($total_sql);

        $rows_sql = $wpdb->prepare(
            "SELECT * FROM {$table}{$where} ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            ...array_merge($query_args, [$per_page, $offset])
        );

        $rows = $wpdb->get_results($rows_sql, ARRAY_A);

        $landings = get_posts([
            'post_type' => self::LANDING_POST_TYPE,
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        echo '<div class="tbx-panel">';
        echo '<p>Registro local de envíos recibidos por <code>/wp-json/tibox/v1/lead</code>.</p>';

        echo '<form method="get" class="tbx-filters tbx-leads-filters">';
        echo '<input type="hidden" name="page" value="tibox-admin-leads">';
        echo '<label for="tibox-landing-filter"><strong>Landing:</strong></label> ';
        echo '<select id="tibox-landing-filter" name="landing_id">';
        echo '<option value="0">Todas las landings</option>';

        foreach ($landings as $landing) {
            echo '<option value="' . esc_attr((string) $landing->ID) . '"' .
                selected($landing_filter, $landing->ID, false) . '>' .
                esc_html($landing->post_title ?: ('Landing #' . $landing->ID)) .
                '</option>';
        }

        echo '</select> ';
        echo '<label for="tbx-leads-month">Mes <input id="tbx-leads-month" type="month" name="mes" value="' .
            esc_attr($month_filter) . '"></label>';
        echo '<label for="tbx-leads-search">Buscar <input id="tbx-leads-search" type="search" name="buscar" value="' .
            esc_attr($search_filter) . '" placeholder="Nombre, correo, empresa o RUT"></label>';
        submit_button('Filtrar', 'secondary', '', false);

        if ($landing_filter > 0 || $month_filter !== '' || $search_filter !== '') {
            echo ' <a class="button" href="' .
                esc_url(
                    admin_url('admin.php?page=tibox-admin-leads')
                ) .
                '">Quitar filtro</a>';
        }

        echo '</form>';

        echo '<p><strong>Total:</strong> ' . esc_html((string) $total) . ' leads. ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(
            add_query_arg(['action' => 'tbx_export_leads', 'landing_id' => $landing_filter, 'mes' => $month_filter, 'buscar' => $search_filter], admin_url('admin-post.php')),
            'tbx_export_leads'
        )) . '">Exportar CSV</a></p>';

        if ($rows === []) {
            echo '<div class="notice notice-info inline"><p>';
            echo 'Aún no hay leads guardados';
            if ($landing_filter > 0) {
                echo ' para esta landing';
            }
            echo '.</p></div>';
            echo '</div>';
            return;
        }

        self::render_leads_rows_table($rows, true);

        $pages = max(1, (int) ceil($total / $per_page));

        if ($pages > 1) {
            $base_args = [
                'page' => 'tibox-admin-leads',
            ];

            if ($landing_filter > 0) {
                $base_args['landing_id'] = $landing_filter;
            }
            if ($month_filter !== '') {
                $base_args['mes'] = $month_filter;
            }
            if ($search_filter !== '') {
                $base_args['buscar'] = $search_filter;
            }

            echo '<div class="tablenav"><div class="tablenav-pages">';

            if ($page > 1) {
                $prev = add_query_arg(
                    array_merge($base_args, ['paged' => $page - 1]),
                    admin_url('admin.php')
                );
                echo '<a class="button" href="' . esc_url($prev) . '">← Anterior</a> ';
            }

            echo '<span style="margin:0 10px;">Página ' .
                esc_html((string) $page) . ' de ' .
                esc_html((string) $pages) .
                '</span>';

            if ($page < $pages) {
                $next = add_query_arg(
                    array_merge($base_args, ['paged' => $page + 1]),
                    admin_url('admin.php')
                );
                echo '<a class="button" href="' . esc_url($next) . '">Siguiente →</a>';
            }

            echo '</div></div>';
        }

        echo '</div>';
    }

    private static function render_leads_rows_table(
        array $rows,
        bool $show_landing = false
    ): void {
        echo '<div class="tbx-leads-scroll">';
        echo '<table class="widefat striped tbx-table tbx-leads-table"><thead><tr>';
        echo '<th>Fecha</th>';
        if ($show_landing) {
            echo '<th>Landing</th>';
        }
        echo '<th>Contacto</th><th>Empresa y solicitud</th><th>Origen</th><th>Detalles</th>';
        if ($show_landing) {
            echo '<th class="tbx-leads-actions">Acciones</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $landing_id = (int) $row['landing_id'];
            $title = $landing_id ? get_the_title($landing_id) : '';
            $source = trim((string) ($row['utm_source'] ?? ''));
            $medium = trim((string) ($row['utm_medium'] ?? ''));
            $email = sanitize_email((string) ($row['email'] ?? ''));
            $phone = (string) ($row['phone'] ?? '');

            echo '<tr>';
            echo '<td class="tbx-leads-date"><strong>' . esc_html(mysql2date('d-m-Y', $row['created_at'])) .
                '</strong><small>' . esc_html(mysql2date('H:i', $row['created_at'])) . '</small></td>';

            if ($show_landing) {
                echo '<td class="tbx-leads-landing">';
                if ($landing_id > 0) {
                    $landing_name = $title !== '' ? $title : 'Landing #' . $landing_id;
                    $edit_url = get_edit_post_link($landing_id);
                    if ($edit_url) {
                        echo '<a href="' . esc_url($edit_url) . '"><strong>' . esc_html($landing_name) . '</strong></a>';
                    } else {
                        echo '<strong>' . esc_html($landing_name) . '</strong>';
                    }
                } else {
                    echo '<span class="tbx-muted">Sin landing asociada</span>';
                }
                echo '</td>';
            }

            echo '<td class="tbx-leads-contact"><strong>' .
                esc_html($row['name'] ?: 'Sin nombre') . '</strong>';
            if ($email !== '') {
                echo '<a href="' . esc_url('mailto:' . $email) . '">' . esc_html($email) . '</a>';
            }
            if ($phone !== '') {
                echo '<small>' . esc_html($phone) . '</small>';
            }
            echo '</td>';

            echo '<td class="tbx-leads-request">';
            if ($row['company'] !== '') {
                echo '<strong>' . esc_html($row['company']) . '</strong>';
            }
            if ($row['area'] !== '') {
                echo '<div>' . esc_html($row['area']) . '</div>';
            }
            if ($row['users'] !== '') {
                echo '<small>Usuarios: ' . esc_html($row['users']) . '</small>';
            }
            if ($row['message'] !== '') {
                echo '<div class="tbx-leads-message">' . esc_html($row['message']) . '</div>';
            }
            echo '</td>';

            echo '<td><span class="tbx-badge tbx-badge--form">Formulario</span>';
            if ($source !== '') {
                echo '<div class="tbx-leads-source"><strong>' . esc_html($source) . '</strong>';
                if ($medium !== '') {
                    echo ' / ' . esc_html($medium);
                }
                echo '</div>';
            } else {
                echo '<div class="tbx-leads-source">Sin campaña registrada</div>';
            }
            if ($row['utm_campaign'] !== '') {
                echo '<small>' . esc_html($row['utm_campaign']) . '</small>';
            }
            echo '</td>';

            echo '<td class="tbx-leads-details"><details><summary>Ver detalles</summary>';
            echo '<div class="tbx-leads-details-body">';
            self::render_meta_line('RUT', (string) $row['rut']);
            self::render_meta_line('Formulario', (string) $row['form_id']);
            self::render_meta_line('Tipo', (string) $row['source_key']);
            self::render_meta_line('ID de envío', (string) $row['submission_id']);
            self::render_meta_line('Página', (string) $row['landing_path']);
            self::render_meta_line('Título', (string) $row['page_title']);
            self::render_meta_line('UTM source', (string) $row['utm_source']);
            self::render_meta_line('UTM medium', (string) $row['utm_medium']);
            self::render_meta_line('UTM campaign', (string) $row['utm_campaign']);
            self::render_meta_line('UTM term', (string) $row['utm_term']);
            self::render_meta_line('UTM content', (string) $row['utm_content']);
            self::render_meta_line('gclid', (string) $row['gclid']);
            self::render_meta_line('gbraid', (string) $row['gbraid']);
            self::render_meta_line('wbraid', (string) $row['wbraid']);
            self::render_meta_line('Consentimiento', !empty($row['privacy']) ? 'Sí' : 'No');
            if ($row['landing_url'] !== '') {
                echo '<div><a href="' . esc_url($row['landing_url']) .
                    '" target="_blank" rel="noopener noreferrer">Abrir página de origen ↗</a></div>';
            }
            echo '</div></details></td>';

            if ($show_landing) {
                echo '<td class="tbx-leads-actions"><form method="post" action="' .
                    esc_url(admin_url('admin-post.php')) .
                    '" onsubmit="return confirm(&quot;¿Eliminar este lead de forma permanente? Esta acción no se puede deshacer.&quot;);">';
                echo '<input type="hidden" name="action" value="tbx_admin_delete_lead">';
                echo '<input type="hidden" name="lead_id" value="' . esc_attr((string) $id) . '">';
                echo '<input type="hidden" name="landing_id" value="' .
                    esc_attr((string) (isset($_GET['landing_id']) ? absint($_GET['landing_id']) : 0)) . '">';
                echo '<input type="hidden" name="paged" value="' .
                    esc_attr((string) (isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1)) . '">';
                echo '<input type="hidden" name="mes" value="' .
                    esc_attr((string) (isset($_GET['mes']) ? sanitize_text_field(wp_unslash($_GET['mes'])) : '')) . '">';
                echo '<input type="hidden" name="buscar" value="' .
                    esc_attr((string) (isset($_GET['buscar']) ? sanitize_text_field(wp_unslash($_GET['buscar'])) : '')) . '">';
                echo wp_nonce_field('tbx_admin_delete_lead_' . $id, '_wpnonce', true, false);
                echo '<button type="submit" class="button-link-delete tbx-leads-delete">Borrar</button>';
                echo '</form></td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p class="tbx-muted">Los detalles de cada lead se despliegan al hacer clic en «Ver detalles».</p>';
    }

    /* =========================================================
     * METABOX LEADS DENTRO DE CADA LANDING
     * ========================================================= */

    public static function add_leads_metabox(): void
    {
        add_meta_box(
            'tibox-lp-leads',
            'Leads recibidos',
            [self::class, 'render_leads_metabox'],
            self::LANDING_POST_TYPE,
            'normal',
            'high'
        );
    }

    public static function render_leads_metabox(WP_Post $post): void
    {
        global $wpdb;

        $table = self::table_name();
        $per_page = 50;
        $page = isset($_GET['tibox_leads_page'])
            ? max(1, absint($_GET['tibox_leads_page']))
            : 1;
        $offset = ($page - 1) * $per_page;

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE landing_id = %d",
                $post->ID
            )
        );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                 WHERE landing_id = %d
                 ORDER BY created_at DESC, id DESC
                 LIMIT %d OFFSET %d",
                $post->ID,
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        echo '<p><strong>Total:</strong> ' . esc_html((string) $total) . ' leads.</p>';

        if ($rows === []) {
            echo '<p>Aún no hay leads guardados para esta landing.</p>';
            return;
        }

        self::render_leads_rows_table($rows, false);

        $pages = max(1, (int) ceil($total / $per_page));

        if ($pages > 1) {
            echo '<p style="margin-top:12px;">Página ' . esc_html((string) $page) . ' de ' . esc_html((string) $pages) . ' &nbsp; ';

            if ($page > 1) {
                echo '<a class="button" href="' . esc_url(
                    add_query_arg('tibox_leads_page', $page - 1)
                ) . '">← Anterior</a> ';
            }

            if ($page < $pages) {
                echo '<a class="button" href="' . esc_url(
                    add_query_arg('tibox_leads_page', $page + 1)
                ) . '">Siguiente →</a>';
            }

            echo '</p>';
        }
    }

    private static function render_meta_line(string $label, string $value): void
    {
        if ($value === '') {
            return;
        }

        echo '<div><strong>' . esc_html($label) . ':</strong> <span style="word-break:break-all;">' . esc_html($value) . '</span></div>';
    }

    /* =========================================================
     * EMAILS
     * ========================================================= */

    private static function send_internal_email(array $data): void
    {
        /* Mismo patrón que los formularios WPForms comerciales */
        $subject = sprintf(
            'Consulta de %s - Enviado desde %s - Rut %s - Landing',
            self::sanitize_header_value($data['name']),
            self::sanitize_header_value(
                $data['page_title'] !== '' ? $data['page_title'] : $data['landing_path']
            ),
            self::sanitize_header_value($data['rut'])
        );

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            sprintf('From: %s <%s>', self::sanitize_header_value((string) self::setting('from_name')), (string) self::setting('from_email')),
        ];

        foreach (self::cc_list() as $cc) {
            $headers[] = 'Cc: ' . $cc;
        }

        if (is_email($data['email'])) {
            $headers[] = sprintf(
                'Reply-To: %s <%s>',
                self::sanitize_header_value($data['name']),
                $data['email']
            );
        }

        $sent = wp_mail(
            (string) self::setting('to'),
            $subject,
            self::build_email_body($data),
            $headers
        );

        if (!$sent) {
            error_log(
                sprintf(
                    '[TIBOX Landing] wp_mail falló. Lead ID: %d, Submission: %s',
                    (int) ($data['lead_id'] ?? 0),
                    $data['submission_id']
                )
            );
        }
    }

    private static function send_confirmation_email(array $data): void
    {
        if (!self::setting('confirmation') || !is_email($data['email'])) {
            return;
        }

        $first_name = self::first_name($data['name']);

        $subject = $first_name !== ''
            ? sprintf('%s, recibimos tu solicitud | Tibox', self::sanitize_header_value($first_name))
            : 'Recibimos tu solicitud | Tibox';

        $sent = wp_mail(
            $data['email'],
            $subject,
            self::build_confirmation_email_body($data),
            [
                'Content-Type: text/html; charset=UTF-8',
                sprintf('From: %s <%s>', self::sanitize_header_value((string) self::setting('from_name')), (string) self::setting('from_email')),
                'Reply-To: Tibox <' . (string) self::setting('reply_to') . '>',
            ]
        );

        if (!$sent) {
            error_log(
                sprintf(
                    '[TIBOX Landing] Falló el correo de confirmación. Lead ID: %d',
                    (int) ($data['lead_id'] ?? 0)
                )
            );
        }
    }

    /*
     * Correo interno con formato tipo WPForms:
     * etiqueta en negrita, valor abajo, línea separadora.
     */
    private static function build_email_body(array $data): string
    {
        $fields = [
            'Nombre' => $data['name'],
            'Correo' => $data['email'],
            'Teléfono' => $data['phone'],
            'Empresa' => $data['company'],
            'RUT empresa' => $data['rut'],
            'Cantidad de usuarios' => $data['users'],
            'Área o servicio' => $data['area'],
            'Mensaje' => $data['message'],
        ];

        $google_ads = ($data['gclid'] !== '' || $data['gbraid'] !== '' || $data['wbraid'] !== '')
            ? 'Sí (clic en anuncio de Google Ads)'
            : 'No';

        $campaign_parts = array_filter([
            $data['utm_campaign'],
            $data['utm_source'] !== '' || $data['utm_medium'] !== ''
                ? trim($data['utm_source'] . ' / ' . $data['utm_medium'], ' /')
                : '',
        ]);

        $origin = [
            'Página' => $data['page_title'] !== '' ? $data['page_title'] : $data['landing_path'],
            'Campaña' => implode(' · ', $campaign_parts),
            'Palabra clave' => $data['utm_term'],
            'Viene de Google Ads' => $google_ads,
        ];

        $reply_button = '';
        if (is_email($data['email'])) {
            $reply_button = sprintf(
                '<a href="mailto:%1$s" style="display:inline-block;background:#FF5322;color:#001233;font-weight:700;font-size:14px;text-decoration:none;padding:11px 20px;border-radius:8px;">Responder a %2$s</a>',
                esc_attr($data['email']),
                esc_html(self::first_name($data['name']))
            );
        }

        $html  = '<div style="margin:0;padding:0;background:#f1f3f6;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f6;padding:30px 12px;font-family:Arial,Helvetica,sans-serif;">';
        $html .= '<tr><td align="center">';
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;overflow:hidden;border:1px solid #e1e5ec;">';

        /* Cabecera */
        $html .= '<tr><td style="padding:22px 30px;border-bottom:1px solid #e1e5ec;">';
        $html .= '<div style="font-size:20px;font-weight:700;letter-spacing:6px;color:#001233;">TIBOX</div>';
        $html .= '<div style="font-size:13px;color:#6b7889;margin-top:4px;">Nueva solicitud desde formulario de landing</div>';
        $html .= '</td></tr>';

        /* Campos del formulario */
        $html .= '<tr><td style="padding:10px 30px 0;">';
        foreach ($fields as $label => $value) {
            $html .= self::wpforms_field($label, (string) $value, $label === 'Mensaje');
        }
        $html .= '</td></tr>';

        if ($reply_button !== '') {
            $html .= '<tr><td style="padding:22px 30px 6px;">' . $reply_button . '</td></tr>';
        }

        /* Origen del lead */
        $html .= '<tr><td style="padding:22px 30px 0;">';
        $html .= '<div style="font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#0056E9;margin-bottom:4px;">Origen del lead</div>';
        foreach ($origin as $label => $value) {
            $html .= self::wpforms_field($label, (string) $value);
        }
        if ($data['landing_url'] !== '') {
            $html .= '<p style="margin:12px 0 0;font-size:13px;"><a href="' . esc_url($data['landing_url']) . '" style="color:#0056E9;">Ver la landing</a></p>';
        }
        $html .= '</td></tr>';

        /* Pie técnico */
        $html .= '<tr><td style="padding:24px 30px 26px;">';
        $html .= '<div style="font-size:11.5px;line-height:1.6;color:#8a94a6;border-top:1px solid #e1e5ec;padding-top:14px;">';
        $html .= 'Lead ID: ' . esc_html((string) ($data['lead_id'] ?? '')) . ' · Formulario: ' . esc_html($data['form_id']);
        $html .= '<br>Recibido: ' . esc_html(wp_date('d-m-Y H:i')) . ' · Submission: ' . esc_html($data['submission_id']);
        if ($data['gclid'] !== '') {
            $html .= '<br>GCLID: ' . esc_html($data['gclid']);
        }
        $html .= '<br>Enviado desde tibox.cl. El lead quedó guardado en WordPress › Landings › Leads.';
        $html .= '</div></td></tr>';

        $html .= '</table></td></tr></table></div>';

        return $html;
    }

    /* Bloque de campo al estilo de las notificaciones de WPForms */
    private static function wpforms_field(string $label, string $value, bool $multiline = false): string
    {
        if (trim($value) === '') {
            return '';
        }

        $display = $multiline ? nl2br(esc_html($value)) : esc_html($value);

        return '<div style="padding:14px 0;border-bottom:1px solid #e1e5ec;">'
            . '<div style="font-size:14px;font-weight:700;color:#001233;margin-bottom:4px;">' . esc_html($label) . '</div>'
            . '<div style="font-size:14px;line-height:1.55;color:#3B4266;">' . $display . '</div>'
            . '</div>';
    }

    /*
     * Correo de confirmación al contacto.
     */
    private static function build_confirmation_email_body(array $data): string
    {
        $first_name = esc_html(self::first_name($data['name']));
        $greeting = $first_name !== '' ? 'Hola ' . $first_name . ',' : 'Hola,';

        $summary = [
            'Empresa' => $data['company'],
            'Cantidad de usuarios' => $data['users'],
            'Teléfono de contacto' => $data['phone'],
        ];

        $summary_rows = '';
        foreach ($summary as $label => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $summary_rows .= '<tr>'
                . '<td style="padding:8px 0;font-size:14px;color:#6b7889;width:44%;">' . esc_html($label) . '</td>'
                . '<td style="padding:8px 0;font-size:14px;color:#001233;font-weight:700;">' . esc_html((string) $value) . '</td>'
                . '</tr>';
        }

        $steps = [
            'Un especialista Tibox revisa tu solicitud.',
            'Te contactamos dentro del próximo día hábil.',
            'Conversamos sobre tu operación actual y te proponemos los servicios adecuados.',
        ];

        $steps_html = '';
        foreach ($steps as $i => $step) {
            $steps_html .= '<tr>'
                . '<td style="width:40px;padding:7px 0;vertical-align:top;">'
                . '<div style="width:28px;height:28px;line-height:28px;border-radius:14px;background:#001233;color:#ffffff;font-size:13px;font-weight:700;text-align:center;">' . ($i + 1) . '</div>'
                . '</td>'
                . '<td style="padding:7px 0;font-size:15px;line-height:1.5;color:#001233;vertical-align:middle;">' . esc_html($step) . '</td>'
                . '</tr>';
        }

        $brand_line = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>'
            . '<td height="4" style="background:#0E9BDB;font-size:0;line-height:0;">&nbsp;</td>'
            . '<td height="4" style="background:#0056E9;font-size:0;line-height:0;">&nbsp;</td>'
            . '<td height="4" style="background:#FF5322;font-size:0;line-height:0;">&nbsp;</td>'
            . '<td height="4" style="background:#FFB200;font-size:0;line-height:0;">&nbsp;</td>'
            . '</tr></table>';

        $html  = '<div style="margin:0;padding:0;background:#f1f3f6;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f3f6;padding:30px 12px;font-family:Arial,Helvetica,sans-serif;">';
        $html .= '<tr><td align="center">';
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;">';

        /* Cabecera navy */
        $html .= '<tr><td style="background:#001233;padding:28px 32px 26px;">';
        $html .= '<div style="font-size:20px;font-weight:700;letter-spacing:6px;color:#ffffff;">TIBOX</div>';
        $html .= '<div style="font-size:24px;font-weight:700;line-height:1.25;color:#ffffff;margin-top:18px;">Recibimos tu solicitud</div>';
        $html .= '<div style="font-size:15px;line-height:1.5;color:#c7d3e4;margin-top:6px;">Gracias por contactar a Tibox.</div>';
        $html .= '</td></tr>';
        $html .= '<tr><td>' . $brand_line . '</td></tr>';

        /* Cuerpo */
        $html .= '<tr><td style="padding:28px 32px 8px;">';
        $html .= '<p style="margin:0 0 14px;font-size:16px;line-height:1.6;color:#001233;">' . $greeting . '</p>';
        $html .= '<p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#3B4266;">Tu solicitud de evaluación quedó registrada. Estos son los próximos pasos:</p>';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $steps_html . '</table>';
        $html .= '</td></tr>';

        if ($summary_rows !== '') {
            $html .= '<tr><td style="padding:18px 32px 0;">';
            $html .= '<div style="background:#F4F6FA;border-radius:10px;padding:16px 20px;">';
            $html .= '<div style="font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:#0056E9;margin-bottom:4px;">Resumen de tu solicitud</div>';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $summary_rows . '</table>';
            $html .= '</div></td></tr>';
        }

        $html .= '<tr><td style="padding:22px 32px 0;">';
        $html .= '<p style="margin:0;font-size:14px;line-height:1.6;color:#3B4266;">La solicitud de evaluación no implica la contratación del servicio.</p>';
        $html .= '</td></tr>';

        /* Contacto */
        $html .= '<tr><td style="padding:22px 32px 30px;">';
        $html .= '<div style="border-top:1px solid #e1e5ec;padding-top:18px;font-size:14px;line-height:1.7;color:#3B4266;">';
        $html .= '¿Necesitas hablar antes con nosotros? Responde este correo o escríbenos a ';
        $html .= '<a href="mailto:contacto@tibox.cl" style="color:#0056E9;font-weight:700;text-decoration:none;">contacto@tibox.cl</a>';
        $html .= ' · <a href="tel:+56752600330" style="color:#0056E9;font-weight:700;text-decoration:none;">+56 (75) 2600330</a>';
        $html .= '</div></td></tr>';

        $html .= '</table>';

        /* Pie */
        $html .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;">';
        $html .= '<tr><td style="padding:16px 32px;font-size:12px;line-height:1.6;color:#8a94a6;text-align:center;">';
        $html .= 'Tibox · Servicios TI gestionados · Santiago y Curicó<br>';
        $html .= 'Recibes este correo porque completaste un formulario en <a href="https://www.tibox.cl" style="color:#8a94a6;">tibox.cl</a>. ';
        $html .= '<a href="https://www.tibox.cl/aviso-de-privacidad/" style="color:#8a94a6;">Aviso de privacidad</a>';
        $html .= '</td></tr></table>';

        $html .= '</td></tr></table></div>';

        return $html;
    }

    /* =========================================================
     * VALIDACIONES / HELPERS
     * ========================================================= */

    private static function validate_chilean_rut(string $rut): bool
    {
        $rut = strtoupper($rut);
        $rut = preg_replace('/[^0-9K]/', '', $rut);

        if (!is_string($rut) || strlen($rut) < 2) {
            return false;
        }

        $body = substr($rut, 0, -1);
        $provided_dv = substr($rut, -1);

        if ($body === '' || !ctype_digit($body)) {
            return false;
        }

        $sum = 0;
        $multiplier = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += ((int) $body[$i]) * $multiplier;
            $multiplier = $multiplier === 7 ? 2 : $multiplier + 1;
        }

        $result = 11 - ($sum % 11);

        if ($result === 11) {
            $expected_dv = '0';
        } elseif ($result === 10) {
            $expected_dv = 'K';
        } else {
            $expected_dv = (string) $result;
        }

        return hash_equals($expected_dv, $provided_dv);
    }

    private static function first_name(string $full_name): string
    {
        $trimmed = trim($full_name);

        if ($trimmed === '') {
            return '';
        }

        $parts = preg_split('/\s+/', $trimmed);
        return $parts[0] ?? $trimmed;
    }

    private static function is_same_site_request(
        WP_REST_Request $request
    ): bool {
        $site_host = wp_parse_url(home_url('/'), PHP_URL_HOST);
        $origin = $request->get_header('origin');
        $referer = $request->get_header('referer');

        if ($origin !== '') {
            return wp_parse_url($origin, PHP_URL_HOST) === $site_host;
        }

        if ($referer !== '') {
            return wp_parse_url($referer, PHP_URL_HOST) === $site_host;
        }

        return true;
    }

    private static function check_rate_limit(): bool
    {
        $ip = self::get_client_ip();
        $key = 'tibox_lp_rate_' . md5(
            wp_salt('auth') . '|' . $ip
        );

        $attempts = (int) get_transient($key);

        if ($attempts >= self::MAX_REQUESTS_PER_WINDOW) {
            return false;
        }

        set_transient(
            $key,
            $attempts + 1,
            self::RATE_LIMIT_SECONDS
        );

        return true;
    }

    private static function get_client_ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return sanitize_text_field(wp_unslash($ip));
    }

    private static function to_boolean(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private static function sanitize_header_value(string $value): string
    {
        return trim(str_replace(["\r", "\n"], '', $value));
    }

    private static function response(
        bool $success,
        bool $lead_created,
        string $message,
        int $status,
        string $submission_id = '',
        int $lead_id = 0
    ): WP_REST_Response {
        $payload = [
            'success' => $success,
            'lead_created' => $lead_created,
            'message' => $message,
        ];

        if ($submission_id !== '') {
            $payload['submission_id'] = $submission_id;
        }

        if ($lead_id > 0) {
            $payload['lead_id'] = $lead_id;
        }

        return new WP_REST_Response($payload, $status);
    }
}

TIBOX_Landing_Form_API::init();

}
