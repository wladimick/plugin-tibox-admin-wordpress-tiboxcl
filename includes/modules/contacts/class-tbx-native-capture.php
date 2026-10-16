<?php
/**
 * Captación de formularios confirmados directamente desde WordPress.
 *
 * Opt-in y mutuamente excluyente con el capturador Tibox Leads 1.0.0.
 * Guarda contactos en la MISMA tabla que el importador histórico, sin modificar
 * ni borrar los datos importados; no envía correos, GA4 ni eventos adicionales.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Native_Contacts
{
    public const OPTION = 'tbx_contacts_native_enabled';
    public const CLICK_OPTION = 'tbx_contacts_click_tracking_enabled';
    private const SEQUENCE_VERSION = '1.0.0';
    private const SEQUENCE_OPTION = 'tbx_native_contact_sequence_version';
    private static array $seen = [];
    public const WA_OPTION = 'tbx_admin_contact_whatsapp';
    private static function whatsapp_settings(): array
    {
        $saved = get_option(self::WA_OPTION, []);
        $defaults = [
            'enabled' => '0', 'number' => '', 'label' => 'Cotizar por WhatsApp',
            'message' => 'Hola, estoy viendo {pagina} en su sitio web y me gustaría recibir una cotización.',
            'side' => 'right',
            'unify' => '0',
            'gtm_legacy' => '0',
        ];
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }


    public static function sequence_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'tbx_admin_contact_sequence';
    }

    public static function enabled(): bool
    {
        return get_option(self::OPTION, '0') === '1';
    }

    public static function legacy_active(): bool
    {
        return function_exists('tbx_leads_insert');
    }

    public static function wa_button_enabled(): bool
    {
        $wa = self::whatsapp_settings();
        return ($wa['enabled'] ?? '') === '1' &&
            (bool) preg_match('/^[0-9]{8,15}$/', (string) ($wa['number'] ?? ''));
    }

    /**
     * TIBOX Admin es dueño del botón, pero mientras TIBOX Leads siga activo
     * este último registra sus clics, asigna referencias y sincroniza Contactos.
     * Solo ajusta la configuración efectiva del frontend; NO cambia la opción
     * que el plugin antiguo tiene guardada en la base de datos.
     */
    public static function legacy_whatsapp_frontend($settings)
    {
        if (is_admin() || !self::legacy_active() || !self::wa_button_enabled()) {
            return $settings;
        }
        $wa = self::whatsapp_settings();
        $settings = is_array($settings) ? $settings : [];
        $settings['wa_on'] = 0; // Evita mostrar dos botones flotantes.
        $settings['wa_number'] = $wa['number'];
        $settings['wa_message'] = $wa['message'];
        $settings['wa_label'] = $wa['label'];
        $settings['wa_side'] = $wa['side'];
        $settings['wa_ref'] = 1; // Ref: XXXXXX sigue viniendo del capturador legado.
        $settings['unify'] = $wa['unify'] === '1' ? 1 : 0;
        return $settings;
    }

    public static function can_capture(): bool
    {
        // Exclusivamente formularios confirmados; nunca duplicar el plugin antiguo.
        return self::enabled() && !self::legacy_active() && !is_user_logged_in();
    }

    public static function click_tracking_enabled(): bool
    {
        return get_option(self::CLICK_OPTION, '0') === '1';
    }

    public static function can_capture_clicks(): bool
    {
        // Control independiente de formularios. Si hay TIBOX Leads, este captura.
        return self::click_tracking_enabled() && !self::legacy_active() && !is_user_logged_in();
    }

    public static function init(): void
    {
        add_action('init', [self::class, 'install']);
        add_action('admin_post_tbx_contacts_native_settings', [self::class, 'save_settings']);
        add_action('admin_post_tbx_contacts_wa_settings', [self::class, 'save_wa_settings']);
        add_action('wpforms_process_complete', [self::class, 'wpforms'], 20, 4);
        add_action('elementor_pro/forms/new_record', [self::class, 'elementor'], 20, 2);
        add_action('wpcf7_mail_sent', [self::class, 'contact_form_7'], 20, 1);
        add_action('rest_api_init', [self::class, 'register_click_route']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_clicks']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_wa_button'], 20);
        add_filter('option_tbx_leads_settings', [self::class, 'legacy_whatsapp_frontend'], 20, 1);
    }

    public static function install(): void
    {
        if (get_option(self::SEQUENCE_OPTION) === self::SEQUENCE_VERSION) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $t = self::sequence_table();
        $charset = $wpdb->get_charset_collate();
        // No guarda datos personales: solo reserva IDs bajo la fuente "native".
        dbDelta("CREATE TABLE {$t} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id)
        ) {$charset};");
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($t))) === $t) {
            update_option(self::SEQUENCE_OPTION, self::SEQUENCE_VERSION, false);
        }
    }

    private static function scalar($value, int $max = 190): string
    {
        if (!is_scalar($value)) {
            return '';
        }
        return mb_substr(sanitize_text_field((string) $value), 0, $max);
    }

    private static function is_sensitive(string $name, string $type): bool
    {
        return (bool) preg_match('/pass|token|nonce|captcha|credit|card|tarjeta|cvv|clave|password|file|upload|firma|signature|payment|adjunto/i', $name . ' ' . $type);
    }

    /**
     * Retiene únicamente campos de texto no sensibles y que el cliente envió.
     * No conserva contraseñas, archivos, tokens ni campos ocultos de sistemas.
     */
    public static function normalize_fields(array $fields): array
    {
        $out = [];
        foreach (array_slice($fields, 0, 100) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $name = self::scalar($item['n'] ?? '', 100);
            $label = self::scalar($item['l'] ?? '', 100);
            $type = sanitize_key(self::scalar($item['t'] ?? '', 30));
            if ($name === '' || str_starts_with($name, '_') || $type === 'hidden' ||
                self::is_sensitive($name . ' ' . $label, $type)) {
                continue;
            }
            $raw = $item['v'] ?? '';
            // Casillas múltiples/selecciones de Elementor, WPForms y CF7.
            // No se incluyen estructuras anidadas, archivos ni objetos.
            if (is_array($raw)) {
                $values = [];
                foreach (array_slice($raw, 0, 25) as $v) {
                    if (is_scalar($v)) {
                        $values[] = sanitize_text_field((string) $v);
                    }
                }
                $raw = implode(', ', $values);
            }
            if (!is_scalar($raw)) {
                continue;
            }
            $value = mb_substr(sanitize_textarea_field((string) $raw), 0, 2000);
            if ($value === '') {
                continue;
            }
            $out[] = ['n' => $name, 'l' => $label ?: $name, 'v' => $value, 't' => $type];
        }
        return $out;
    }

    public static function extract_contact(array $fields): array
    {
        $contact = ['name' => '', 'email' => '', 'phone' => '', 'message' => ''];
        foreach ($fields as $f) {
            $key = mb_strtolower(($f['n'] ?? '') . ' ' . ($f['l'] ?? ''));
            $type = $f['t'] ?? '';
            $value = (string) ($f['v'] ?? '');
            if ($contact['email'] === '' && ($type === 'email' || is_email($value))) {
                $contact['email'] = sanitize_email($value);
            } elseif ($contact['phone'] === '' &&
                (in_array($type, ['tel', 'phone'], true) || preg_match('/tel|fono|phone|celular|movil|móvil|whats/', $key))) {
                $contact['phone'] = self::scalar($value, 60);
            } elseif ($contact['name'] === '' &&
                (in_array($type, ['name', 'text'], true) || preg_match('/nombre|name/', $key)) &&
                preg_match('/nombre|name/', $key) &&
                !preg_match('/empresa|company|instituci|colegio|usuario|user/', $key)) {
                $contact['name'] = self::scalar($value, 190);
            } elseif ($contact['message'] === '' &&
                ($type === 'textarea' || preg_match('/mensaje|message|comentario|consulta/', $key))) {
                $contact['message'] = mb_substr($value, 0, 5000);
            }
        }
        return $contact;
    }

    private static function posted(string $field): string
    {
        $val = isset($_POST[$field]) ? wp_unslash($_POST[$field]) : '';
        return self::scalar($val, 190);
    }

    private static function origin_url(): string
    {
        // Para no guardar URLs con correos, tokens u otros query params:
        // solo ruta en el mismo host del WordPress.
        $raw = self::posted('page_url');
        if ($raw === '') {
            $raw = (string) wp_get_referer();
        }
        $home_host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
        $host = (string) wp_parse_url($raw, PHP_URL_HOST);
        if ($host === '' || strcasecmp($home_host, $host) !== 0) {
            return '';
        }
        $path = TBX_Web_Vitals::normalize_path($raw);
        if ($path === '/[redactado]/') {
            return '';
        }
        return home_url($path);
    }

    private static function attribution(): array
    {
        $referrer = (string) wp_get_referer();
        $query = (string) wp_parse_url($referrer, PHP_URL_QUERY);
        $from_url = [];
        if ($query !== '') {
            parse_str($query, $from_url);
        }
        $out = [];
        foreach (['source', 'medium', 'campaign', 'term', 'content'] as $key) {
            $posted = self::posted('utm_' . $key);
            $raw = $posted !== '' ? $posted : ($from_url['utm_' . $key] ?? '');
            $out[$key] = self::scalar($raw, 150);
        }
        return $out;
    }

    private static function record(string $origin, string $form_id, string $form_name, array $raw): void
    {
        if (!self::can_capture()) {
            return;
        }
        $fields = self::normalize_fields($raw);
        $contact = self::extract_contact($fields);
        if (!$fields || (!array_filter($contact) && !$form_id)) {
            return;
        }
        $form_id = self::scalar($form_id, 100);
        $idempotency = hash('sha256', wp_json_encode([$origin, $form_id, $fields]));
        if (isset(self::$seen[$idempotency])) {
            return; // hooks duplicados en la misma solicitud.
        }
        self::$seen[$idempotency] = true;

        $data = [
            'type' => 'form',
            'origin' => $origin,
            'form_id' => $form_id,
            'form_name' => self::scalar($form_name, 190),
            'page_url' => self::origin_url(),
            'page_title' => self::posted('page_title'),
            'name' => $contact['name'],
            'email' => $contact['email'],
            'phone' => $contact['phone'],
            'message' => $contact['message'],
            'fields' => wp_json_encode($fields, JSON_UNESCAPED_UNICODE),
            ...self::attribution(),
            ...TBX_Analytics_Context::classify((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')),
        ];
        self::insert_native($data);
    }

    /**
     * Escribe contactos nativos de formularios o clics en una secuencia
     * independiente y monotónica, sin reutilizar los IDs de Prodata.
     */
    private static function insert_native(array $data): bool
    {
        self::install();
        TBX_Contacts::install();
        global $wpdb;
        $reserved = $wpdb->insert(self::sequence_table(), ['created_at' => current_time('mysql', true)], ['%s']);
        if ($reserved !== 1) {
            error_log('[TIBOX Contacts] No se pudo reservar ID de registro.');
            return false;
        }
        $now = current_time('mysql', true);
        $base = [
            'source_plugin' => 'tibox-admin-native',
            'legacy_id' => (int) $wpdb->insert_id,
            'imported_at' => $now,
            'created_at' => $now,
        ];
        if ($wpdb->insert(TBX_Contacts::table(), array_merge($base, $data)) !== 1) {
            error_log('[TIBOX Contacts] Error al insertar registro nativo.');
            return false;
        }
        return true;
    }

    public static function wpforms($fields, $entry, $form_data, $entry_id): void
    {
        if (!self::can_capture()) {
            return;
        }
        $raw = [];
        foreach ((array) $fields as $f) {
            if (!is_array($f)) {
                continue;
            }
            $raw[] = [
                'n' => 'field_' . self::scalar($f['id'] ?? '', 30),
                'l' => $f['name'] ?? '',
                'v' => $f['value'] ?? '',
                't' => $f['type'] ?? '',
            ];
        }
        self::record('wpforms', self::scalar($form_data['id'] ?? '', 100),
            self::scalar($form_data['settings']['form_title'] ?? '', 190), $raw);
    }

    public static function elementor($record, $handler = null): void
    {
        if (!self::can_capture() || !is_object($record) || !method_exists($record, 'get')) {
            return;
        }
        $raw = [];
        foreach ((array) $record->get('fields') as $id => $field) {
            if (!is_array($field)) {
                continue;
            }
            $raw[] = [
                'n' => $id,
                'l' => $field['title'] ?? $id,
                'v' => $field['value'] ?? '',
                't' => $field['type'] ?? '',
            ];
        }
        self::record('elementor',
            method_exists($record, 'get_form_settings') ? self::scalar($record->get_form_settings('id'), 100) : '',
            method_exists($record, 'get_form_settings') ? self::scalar($record->get_form_settings('form_name'), 190) : '',
            $raw
        );
    }

    public static function contact_form_7($contact_form): void
    {
        if (!self::can_capture() || !class_exists('WPCF7_Submission') ||
            !is_object($contact_form) || !method_exists($contact_form, 'id')) {
            return;
        }
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }
        $raw = [];
        foreach ((array) $submission->get_posted_data() as $name => $value) {
            if (!is_string($name) || str_starts_with($name, '_')) {
                continue;
            }
            $raw[] = [
                'n' => $name,
                'l' => $name,
                'v' => $value,
                't' => str_contains(mb_strtolower($name), 'mail') ? 'email' : 'text',
            ];
        }
        self::record('cf7', (string) $contact_form->id(),
            method_exists($contact_form, 'title') ? (string) $contact_form->title() : '', $raw);
    }

    public static function enqueue_clicks(): void
    {
        if (!self::can_capture_clicks()) {
            return;
        }
        $file = TBX_ADMIN_DIR . 'assets/tibox-contact-clicks.js';
        wp_enqueue_script(
            'tbx-native-contact-clicks',
            TBX_ADMIN_URL . 'assets/tibox-contact-clicks.js',
            [],
            (string) filemtime($file),
            ['in_footer' => true, 'strategy' => 'defer']
        );

        $wa = self::whatsapp_settings();
        wp_add_inline_script(
            'tbx-native-contact-clicks',
            'window.TBX_CONTACT_ENDPOINT=' . wp_json_encode(esc_url_raw(rest_url('tibox/v1/contact-event'))) .
            ';window.TBX_CONTACT_LINK_CONFIG=' . wp_json_encode([
                'unify' => $wa['unify'] === '1',
                'number' => $wa['number'],
                'message' => $wa['message'],
                'site' => get_bloginfo('name'),
                'gtm_legacy' => ($wa['gtm_legacy'] ?? '0') === '1',
            ]) . ';',
            'before'
        );
    }

    /**
     * Botón de WhatsApp independiente: funciona con captura nativa apagada
     * y con TIBOX Leads 1.0.0 activo. Un único botón, un capturador.
     */
    public static function enqueue_wa_button(): void
    {
        if (!self::wa_button_enabled()) {
            return;
        }
        $wa = self::whatsapp_settings();
        wp_enqueue_style(
            'tbx-native-contact-button',
            TBX_ADMIN_URL . 'assets/tibox-native-contact-button.css',
            [],
            TBX_ADMIN_VERSION
        );
        $file = TBX_ADMIN_DIR . 'assets/tibox-wa-button.js';
        wp_enqueue_script(
            'tbx-independent-wa-button',
            TBX_ADMIN_URL . 'assets/tibox-wa-button.js',
            [],
            (string) filemtime($file),
            ['in_footer' => true, 'strategy' => 'defer']
        );
        wp_add_inline_script(
            'tbx-independent-wa-button',
            'window.TBX_CONTACT_WA_BUTTON=' . wp_json_encode([
                'number' => $wa['number'],
                'label' => $wa['label'],
                'message' => $wa['message'],
                'side' => $wa['side'],
            ]) . ';',
            'before'
        );
    }

    public static function register_click_route(): void
    {
        register_rest_route('tibox/v1', '/contact-event', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'receive_click'],
            'permission_callback' => '__return_true',
        ]);
    }

    public static function receive_click(WP_REST_Request $request): WP_REST_Response
    {
        if (!self::can_capture_clicks()) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $origin = $request->get_header('origin') ?: $request->get_header('referer');
        $home_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (!$origin || strtolower((string) wp_parse_url($origin, PHP_URL_HOST)) !== $home_host) {
            return new WP_REST_Response(['ok' => false], 403);
        }
        $ua = (string) $request->get_header('user_agent');
        if ($ua === '' || preg_match('/bot|spider|crawler|headless|lighthouse|pingdom|curl|wget/i', $ua)) {
            return new WP_REST_Response(['ok' => false], 202);
        }
        $ipkey = 'tbx_cclk_' . md5(wp_salt('nonce') . ($_SERVER['REMOTE_ADDR'] ?? ''));
        $used = (int) get_transient($ipkey);
        if ($used >= 40) {
            return new WP_REST_Response(['ok' => false], 429);
        }
        set_transient($ipkey, $used + 1, MINUTE_IN_SECONDS);
        $body = json_decode((string) $request->get_body(), true);
        if (!is_array($body)) {
            return new WP_REST_Response(['ok' => false], 400);
        }
        $type = isset($body['type']) && is_string($body['type']) ? sanitize_key($body['type']) : '';
        if (!in_array($type, ['whatsapp', 'phone', 'email'], true)) {
            return new WP_REST_Response(['ok' => false], 400);
        }
        $path = TBX_Web_Vitals::normalize_path($body['path'] ?? '/');
        if ($path === '/[redactado]/') {
            $path = '/';
        }
        $raw_ref = $body['ref'] ?? '';
        $ref = is_string($raw_ref) ? strtoupper(preg_replace('/[^A-Z0-9]/i', '', $raw_ref)) : '';
        if (!preg_match('/^[A-HJ-NP-Z2-9]{6}$/', $ref) || $type !== 'whatsapp') {
            $ref = '';
        }
        $raw_dest = $body['destination'] ?? '';
        $dest = self::scalar($raw_dest, 190);
        if ($type === 'whatsapp') {
            $dest = preg_replace('/\D/', '', $dest);
            if (!preg_match('/^[0-9]{8,15}$/', $dest)) {
                $dest = '';
                $ref = '';
            }
        }
        if ($type === 'email') {
            $dest = sanitize_email($dest);
        }
        $campaign = isset($body['campaign']) && is_array($body['campaign']) ? $body['campaign'] : [];
        $attrs = [];
        foreach (['source','medium','campaign'] as $key) {
            $candidate = $campaign[$key] ?? '';
            $candidate = self::scalar($candidate, 100);
            $attrs[$key] = preg_match('/@|https?:|%40/i', $candidate) ? '' : $candidate;
        }
        $raw_origin = isset($body['origin']) && is_string($body['origin'])
            ? sanitize_key($body['origin']) : '';
        $data = [
            'type' => $type,
            'origin' => $raw_origin === 'boton-tibox' ? 'boton-tibox' : 'enlace',
            'location' => sanitize_key(self::scalar($body['location'] ?? '', 40)),
            'button_text' => self::scalar($body['button_text'] ?? '', 100),
            'destination' => $dest,
            'ref_code' => $ref,
            'page_url' => home_url($path),
            ...$attrs,
            ...TBX_Analytics_Context::classify($ua),
        ];
        return new WP_REST_Response(['ok' => self::insert_native($data)], 200);
    }


    public static function save_wa_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Método no permitido.', '', ['response' => 405]);
        }
        check_admin_referer('tbx_contacts_wa_settings');
        $in = isset($_POST['tbx_wa']) && is_array($_POST['tbx_wa']) ? wp_unslash($_POST['tbx_wa']) : [];
        $number = preg_replace('/\\D/', '', (string) ($in['number'] ?? ''));
        $valid = (bool) preg_match('/^[0-9]{8,15}$/', $number);
        $config = [
            'enabled' => !empty($in['enabled']) && $valid ? '1' : '0',
            'unify' => !empty($in['unify']) && $valid ? '1' : '0',
            'gtm_legacy' => !empty($in['gtm_legacy']) ? '1' : '0',
            'number' => $valid ? $number : '',
            'label' => self::scalar($in['label'] ?? 'Cotizar por WhatsApp', 80),
            'message' => mb_substr(sanitize_textarea_field((string) ($in['message'] ?? '')), 0, 300),
            'side' => ($in['side'] ?? '') === 'left' ? 'left' : 'right',
        ];
        update_option(self::WA_OPTION, $config, false);
        update_option(self::CLICK_OPTION, !empty($in['track_clicks']) ? '1' : '0', false);
        wp_safe_redirect(add_query_arg('wa_saved', $valid ? '1' : '0', TBX_Admin::tab_url('contactos')));
        exit;
    }

    public static function render_wa_settings(): void
    {
        $wa = self::whatsapp_settings();
        echo '<div class="tbx-import-card"><h2>Botón flotante de WhatsApp (opcional)</h2>';
        echo '<p>Este botón funciona independientemente de la captura directa de formularios. Puedes activarlo mientras TIBOX Leads sigue instalado: TIBOX Admin muestra un solo botón y TIBOX Leads conserva el registro de clics y las referencias WhatsApp.</p>';
        if (self::legacy_active()) {
            echo '<p class="tbx-muted"><strong>Convivencia:</strong> los ajustes del botón se administran aquí; el registro de clics sigue funcionando en el plugin antiguo hasta que migremos completamente la captura.</p>';
        } elseif (!self::click_tracking_enabled()) {
            echo '<p class="tbx-muted"><strong>Atención:</strong> el botón abre WhatsApp, pero el registro individual de clics está desactivado. Puedes habilitarlo aquí sin activar la captura de formularios.</p>';
        } else {
            echo '<p class="tbx-muted"><strong>Seguimiento preparado:</strong> se registran los clics de visitantes. Los administradores con sesión iniciada no se contabilizan; prueba desde una ventana privada sin iniciar sesión.</p>';
        }
        if (isset($_GET['wa_saved'])) {
            $ok = sanitize_key(wp_unslash($_GET['wa_saved'])) === '1';
            echo '<div class="notice notice-' . ($ok ? 'success' : 'warning') .
                ' inline"><p>' . ($ok ? 'Ajustes del botón guardados.' : 'Revisa el número: debe contener de 8 a 15 dígitos.') .
                '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="tbx_contacts_wa_settings">';
        wp_nonce_field('tbx_contacts_wa_settings');
        echo '<p><label><input type="checkbox" name="tbx_wa[enabled]" value="1"' .
            checked($wa['enabled'], '1', false) . '> Mostrar botón flotante</label></p>';
        echo '<p><label><input type="checkbox" name="tbx_wa[track_clicks]" value="1"' .
            checked(self::click_tracking_enabled(), true, false) .
            '> Registrar clics de WhatsApp, teléfono y correo en Contactos (independiente de los formularios)</label></p>';
        if (self::legacy_active()) {
            echo '<p class="tbx-muted">Puedes dejar preparado el seguimiento nuevo, pero no se activará mientras TIBOX Leads esté instalado y activo. Este seguirá registrando los clics sin duplicados.</p>';
        }
        echo '<p><label>Número (con código de país, solo dígitos)<br><input class="regular-text" type="text" name="tbx_wa[number]" value="' .
            esc_attr((string) $wa['number']) . '" placeholder="56912345678"></label></p>';
        echo '<p><label>Etiqueta<br><input class="regular-text" type="text" name="tbx_wa[label]" value="' .
            esc_attr((string) $wa['label']) . '"></label></p>';
        echo '<p><label>Mensaje predeterminado<br><textarea class="large-text" rows="3" name="tbx_wa[message]">' .
            esc_textarea((string) $wa['message']) . '</textarea></label></p>';
        echo '<p class="tbx-muted">Variables permitidas: {pagina} y {sitio}. Las referencias de seis caracteres se agregan a enlaces WhatsApp compatibles cuando hay un capturador activo.</p>';
        echo '<p><label><input type="checkbox" name="tbx_wa[unify]" value="1"' .
            checked((string) $wa['unify'], '1', false) .
            '> Unificar otros enlaces de WhatsApp con este número y mensaje</label></p>';
        echo '<p class="tbx-muted">Opcional: redirige enlaces WhatsApp reconocidos al destino configurado. Dejar desactivado si hay números distintos en el sitio.</p>';
        echo '<p><label><input type="checkbox" name="tbx_wa[gtm_legacy]" value="1"' .
            checked((string) ($wa['gtm_legacy'] ?? '0'), '1', false) .
            '> Compatibilidad Google Tag Manager: emitir eventos tbx_whatsapp_click, tbx_phone_click y tbx_email_click</label></p>';
        echo '<p class="tbx-muted">Solo después de retirar TIBOX Leads y revisar disparadores de GTM. Desactivado por defecto para no duplicar conversiones. Los eventos indican clics, no consultas confirmadas.</p>';
        echo '<p><label>Ubicación <select name="tbx_wa[side]">';
        foreach (['right' => 'Derecha', 'left' => 'Izquierda'] as $v => $label) {
            echo '<option value="' . esc_attr($v) . '"' . selected($wa['side'], $v, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label></p>';
        submit_button('Guardar botón WhatsApp', 'secondary', 'submit', false);
        echo '</form></div>';
    }

    public static function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sin permisos.', '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die('Método no permitido.', '', ['response' => 405]);
        }
        check_admin_referer('tbx_contacts_native_settings');
        $enable = !empty($_POST['native_enabled']);
        if ($enable && self::legacy_active()) {
            update_option(self::OPTION, '0', false);
            wp_safe_redirect(add_query_arg('native', 'legacy_active', TBX_Admin::tab_url('contactos')));
            exit;
        }
        update_option(self::OPTION, $enable ? '1' : '0', false);
        wp_safe_redirect(add_query_arg('native', 'saved', TBX_Admin::tab_url('contactos')));
        exit;
    }

    public static function render_settings(): void
    {
        echo '<div class="tbx-import-card"><h2>Captura directa de formularios</h2>';
        echo '<p>Registra envíos confirmados de WPForms, Elementor y Contact Form 7 sin generar conversiones adicionales. El seguimiento de WhatsApp, teléfono y correo se configura por separado en «Botón flotante de WhatsApp».</p>';
        if (self::legacy_active()) {
            echo '<div class="notice notice-warning inline"><p>El plugin original TIBOX Leads sigue activo: la captura directa está bloqueada para evitar duplicados. Mantén este plugin antiguo durante la importación del historial.</p></div>';
        }
        if (isset($_GET['native']) && sanitize_key(wp_unslash($_GET['native'])) === 'saved') {
            echo '<div class="notice notice-success inline"><p>Configuración guardada.</p></div>';
        }
        echo '<form action="' . esc_url(admin_url('admin-post.php')) . '" method="post">';
        echo '<input type="hidden" name="action" value="tbx_contacts_native_settings">';
        wp_nonce_field('tbx_contacts_native_settings');
        echo '<label><input type="checkbox" name="native_enabled" value="1"' .
            checked(self::enabled(), true, false) . (self::legacy_active() ? ' disabled' : '') .
            '> Registrar formularios directamente desde TIBOX Admin</label>';
        if (self::legacy_active()) {
            echo '<input type="hidden" name="native_enabled" value="0">';
        }
        echo '<p class="tbx-muted">Desactivada por defecto. Primero importa y comprueba el historial, realiza un respaldo y desactiva TIBOX Leads; después habilita esta opción y prueba un formulario de cada tipo. No requiere mantener el plugin anterior.</p>';
        submit_button('Guardar configuración', 'secondary', 'submit', false);
        echo '</form></div>';
    }
}

TBX_Native_Contacts::init();
