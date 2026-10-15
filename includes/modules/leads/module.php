<?php
/**
 * Módulo Leads y formularios.
 * - Endpoint /wp-json/tibox/v1/lead y tabla de leads (clase portada).
 * - Pestañas «Leads» y «Formularios y correos».
 * - Exportación CSV.
 * - Envío a WebOps (antes snippet «WebOps Marketing Lead Bridge»).
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-lead-api.php';

TIBOX_Landing_Form_API::init();

TBX_Admin::register_tab('leads', 'Leads', ['TIBOX_Landing_Form_API', 'render_leads_admin_page'], 20);
TBX_Admin::register_tab('formularios', 'Formularios y correos', 'tbx_leads_render_settings', 60);

/* ---------- Ajustes ---------- */

add_action('admin_post_tbx_save_leads_settings', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado');
    }
    check_admin_referer('tbx_save_leads_settings');

    $in = wp_unslash($_POST['tbx'] ?? []);
    $defaults = TIBOX_Landing_Form_API::defaults();

    $to = sanitize_email($in['to'] ?? '');
    $from = sanitize_email($in['from_email'] ?? '');
    $reply = sanitize_email($in['reply_to'] ?? '');

    $settings = [
        'to' => is_email($to) ? $to : $defaults['to'],
        'cc' => sanitize_textarea_field($in['cc'] ?? ''),
        'from_email' => is_email($from) ? $from : $defaults['from_email'],
        'from_name' => sanitize_text_field($in['from_name'] ?? $defaults['from_name']),
        'reply_to' => is_email($reply) ? $reply : $defaults['reply_to'],
        'confirmation' => empty($in['confirmation']) ? 0 : 1,
        'webops' => empty($in['webops']) ? 0 : 1,
    ];

    update_option(TIBOX_Landing_Form_API::SETTINGS_OPTION, $settings, false);
    wp_safe_redirect(add_query_arg('updated', '1', TBX_Admin::tab_url('formularios')));
    exit;
});

function tbx_leads_render_settings(): void
{
    $s = fn(string $k) => TIBOX_Landing_Form_API::setting($k);
    $webops_ready = defined('TIBOX_WEBOPS_MARKETING_URL') && defined('TIBOX_WEBOPS_MARKETING_SECRET') && defined('TIBOX_WEBOPS_SOURCE_KEY');

    if (isset($_GET['updated'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>';
    }

    echo '<p class="tbx-lead">Destinatarios y correos de los formularios de landing (<code>/wp-json/tibox/v1/lead</code>). Los formularios WPForms se configuran en WPForms.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="tbx-form">';
    wp_nonce_field('tbx_save_leads_settings');
    echo '<input type="hidden" name="action" value="tbx_save_leads_settings">';
    echo '<table class="form-table" role="presentation"><tbody>';

    printf('<tr><th scope="row"><label for="tbx-to">Para</label></th><td><input id="tbx-to" class="regular-text" type="email" name="tbx[to]" value="%s" required><p class="description">Casilla principal que recibe cada lead.</p></td></tr>', esc_attr($s('to')));
    printf('<tr><th scope="row"><label for="tbx-cc">Con copia</label></th><td><textarea id="tbx-cc" class="large-text" rows="4" name="tbx[cc]">%s</textarea><p class="description">Una casilla por línea.</p></td></tr>', esc_textarea($s('cc')));
    printf('<tr><th scope="row"><label for="tbx-from-name">Nombre del remitente</label></th><td><input id="tbx-from-name" class="regular-text" type="text" name="tbx[from_name]" value="%s"></td></tr>', esc_attr($s('from_name')));
    printf('<tr><th scope="row"><label for="tbx-from">Correo del remitente</label></th><td><input id="tbx-from" class="regular-text" type="email" name="tbx[from_email]" value="%s"><p class="description">Usa la misma casilla que envía los WPForms. Si WP Mail SMTP fuerza otro remitente, este valor se ignora.</p></td></tr>', esc_attr($s('from_email')));
    printf('<tr><th scope="row"><label for="tbx-reply">Responder a (confirmación)</label></th><td><input id="tbx-reply" class="regular-text" type="email" name="tbx[reply_to]" value="%s"><p class="description">Si el contacto responde el correo de confirmación, llega aquí.</p></td></tr>', esc_attr($s('reply_to')));
    printf('<tr><th scope="row">Confirmación al contacto</th><td><label><input type="checkbox" name="tbx[confirmation]" value="1"%s> Enviar correo «Recibimos tu solicitud» a quien completa el formulario</label></td></tr>', checked((bool) $s('confirmation'), true, false));
    printf(
        '<tr><th scope="row">WebOps / CRM</th><td><label><input type="checkbox" name="tbx[webops]" value="1"%s> Enviar cada lead a WebOps</label><p class="description">%s</p></td></tr>',
        checked((bool) $s('webops'), true, false),
        $webops_ready ? 'Credenciales encontradas en wp-config.php.' : 'Faltan las constantes TIBOX_WEBOPS_* en wp-config.php: no se enviará nada hasta configurarlas.'
    );

    echo '</tbody></table>';
    submit_button('Guardar ajustes');
    echo '</form>';
}

/* ---------- Exportar CSV ---------- */

add_action('admin_post_tbx_export_leads', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('No autorizado');
    }
    check_admin_referer('tbx_export_leads');

    global $wpdb;
    $table = $wpdb->prefix . 'tibox_landing_leads';
    $landing = isset($_GET['landing_id']) ? absint($_GET['landing_id']) : 0;

    $rows = $landing > 0
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} WHERE landing_id = %d ORDER BY created_at DESC", $landing), ARRAY_A)
        : $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC", ARRAY_A);

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=leads-tibox-' . gmdate('Y-m-d') . '.csv');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); /* BOM para Excel */

    $cols = ['created_at', 'name', 'email', 'phone', 'company', 'rut', 'users', 'area', 'message', 'landing_path', 'page_title', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid', 'form_id', 'submission_id'];
    fputcsv($out, $cols, ';');
    foreach ((array) $rows as $row) {
        $line = [];
        foreach ($cols as $c) {
            $value = (string) ($row[$c] ?? '');
            /* Evita inyección de fórmulas al abrir en Excel */
            if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
                $value = "'" . $value;
            }
            $line[] = $value;
        }
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
});

/* ---------- Borrado seguro de leads ---------- */

/*
 * Solo acepta POST y nonce por registro. El filtro y la paginación se
 * reconstruyen con valores validados; nunca se utiliza un redirect arbitrario.
 */
add_action('admin_post_tbx_admin_delete_lead', function (): void {
    if (!current_user_can('manage_options')) {
        wp_die('No tienes permisos para eliminar leads.', '', ['response' => 403]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('Método no permitido.', '', ['response' => 405]);
    }

    $id = isset($_POST['lead_id']) ? absint(wp_unslash($_POST['lead_id'])) : 0;
    if ($id < 1) {
        wp_die('Registro inválido.', '', ['response' => 400]);
    }
    check_admin_referer('tbx_admin_delete_lead_' . $id);

    global $wpdb;
    $table = $wpdb->prefix . 'tibox_landing_leads';
    $deleted = $wpdb->delete($table, ['id' => $id], ['%d']);

    $args = [
        'tbx_deleted' => $deleted === 1 ? '1' : '0',
    ];
    if (!empty($_POST['landing_id'])) {
        $args['landing_id'] = absint(wp_unslash($_POST['landing_id']));
    }
    if (!empty($_POST['paged'])) {
        $args['paged'] = max(1, absint(wp_unslash($_POST['paged'])));
    }

    wp_safe_redirect(add_query_arg($args, TBX_Admin::tab_url('leads')));
    exit;
});

/* ---------- WebOps ---------- */

/*
 * El módulo es dueño del hook: quita cualquier listener anterior
 * (el snippet «WebOps Marketing Lead Bridge» si quedó activo) para
 * que un lead no se envíe dos veces.
 */
add_action('init', function (): void {
    remove_all_actions('tibox_landing_lead_created');
    add_action('tibox_landing_lead_created', 'tbx_leads_send_to_webops', 10, 1);
}, 1);

function tbx_leads_send_to_webops(array $data): void
{
    if (!TIBOX_Landing_Form_API::setting('webops')) {
        return;
    }
    if (!defined('TIBOX_WEBOPS_MARKETING_URL') || !defined('TIBOX_WEBOPS_MARKETING_SECRET') || !defined('TIBOX_WEBOPS_SOURCE_KEY')) {
        error_log('[TIBOX WebOps] Falta configurar URL, secreto o source_key.');
        return;
    }

    $submission_id = sanitize_text_field($data['submission_id'] ?? '') ?: wp_generate_uuid4();

    $payload = [
        'source_key' => (string) TIBOX_WEBOPS_SOURCE_KEY,
        'form_id' => sanitize_key($data['form_id'] ?? 'tibox-lead-form'),
        'submission_id' => $submission_id,
        'nombre' => $data['name'] ?? '',
        'email' => $data['email'] ?? '',
        'telefono' => $data['phone'] ?? '',
        'empresa' => $data['company'] ?? '',
        'rut' => $data['rut'] ?? '',
        'area' => $data['area'] ?? '',
        'usuarios' => $data['users'] ?? '',
        'mensaje' => $data['message'] ?? '',
        'consent' => !empty($data['privacy']),
        'landing_url' => $data['landing_url'] ?? '',
        'landing_path' => $data['landing_path'] ?? '',
        'page_title' => $data['page_title'] ?? '',
        'utm_source' => $data['utm_source'] ?? '',
        'utm_medium' => $data['utm_medium'] ?? '',
        'utm_campaign' => $data['utm_campaign'] ?? '',
        'utm_term' => $data['utm_term'] ?? '',
        'utm_content' => $data['utm_content'] ?? '',
        'gclid' => $data['gclid'] ?? '',
        'fbclid' => $data['fbclid'] ?? '',
        'gbraid' => $data['gbraid'] ?? '',
        'wbraid' => $data['wbraid'] ?? '',
    ];

    $response = wp_remote_post((string) TIBOX_WEBOPS_MARKETING_URL, [
        'timeout' => 8,
        'headers' => [
            'Authorization' => 'Bearer ' . (string) TIBOX_WEBOPS_MARKETING_SECRET,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ],
        'body' => wp_json_encode($payload),
        'data_format' => 'body',
    ]);

    if (is_wp_error($response)) {
        error_log('[TIBOX WebOps] Error de conexión: ' . $response->get_error_message());
        return;
    }

    $status = wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        error_log(sprintf('[TIBOX WebOps] HTTP %d. submission_id=%s.', $status, $submission_id));
        return;
    }

    error_log(sprintf('[TIBOX WebOps] Envío aceptado. submission_id=%s.', $submission_id));
}

/* ---------- Resumen ---------- */

add_filter('tbx_admin_dashboard_cards', function (array $cards): array {
    global $wpdb;
    $table = $wpdb->prefix . 'tibox_landing_leads';

    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        return $cards;
    }

    $since30 = wp_date('Y-m-d 00:00:00', strtotime('-29 days'));
    $today = wp_date('Y-m-d 00:00:00');

    $last30 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $since30));
    $today_n = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE created_at >= %s", $today));

    $cards[] = [
        'value' => $last30,
        'label' => 'Leads últimos 30 días',
        'note' => $today_n . ' hoy',
        'url' => TBX_Admin::tab_url('leads'),
    ];

    return $cards;
}, 20);
