<?php
/**
 * Ajustes de WhatsApp: el administrador autenticado no puede confundirse
 * con un visitante. Su sesión no registra eventos, pero la opción persiste.
 */
define('ABSPATH', __DIR__ . '/');
$GLOBALS['settings'] = [
    'enabled'=>'1',
    'number'=>'56912345678',
    'label'=>'Cotizar por WhatsApp',
    'message'=>'Hola desde {pagina}',
    'side'=>'right',
    'gtm_legacy'=>'1',
    'unify'=>'0',
];
$GLOBALS['tracking'] = '1';
function add_action(...$args): void {}
function add_filter(...$args): void {}
function is_admin(): bool { return true; }
function is_user_logged_in(): bool { return $GLOBALS['logged'] ?? true; }
function get_option($key, $default = null) {
    return match ($key) {
        'tbx_admin_contact_whatsapp' => $GLOBALS['settings'],
        'tbx_contacts_click_tracking_enabled' => $GLOBALS['tracking'],
        'tbx_contacts_form_gtm_compat_enabled' => $GLOBALS['form_gtm'] ?? '0',
        'tbx_contacts_native_enabled' => $GLOBALS['native'] ?? '0',
        default => $default,
    };
}
function checked($actual, $expected, $echo = true): string { return $actual == $expected ? ' checked="checked"' : ''; }
function selected($actual, $expected, $echo = true): string { return $actual == $expected ? ' selected="selected"' : ''; }
function esc_attr($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_textarea($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value): string { return (string)$value; }
function admin_url($value): string { return '/wp-admin/' . $value; }
function wp_nonce_field($name): void { echo '<input type="hidden" name="_nonce">'; }
function submit_button(...$args): void { echo '<button>Guardar</button>'; }

require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';
ob_start();
TBX_Native_Contacts::render_wa_settings();
$html = ob_get_clean();
if (!str_contains($html, 'Seguimiento preparado') || str_contains($html, 'registro individual de clics está desactivado')) {
    fwrite(STDERR, "Tracking enabled must be shown accurately in admin screen\n"); exit(1);
}
if (!str_contains($html, 'name="tbx_wa[gtm_legacy]" value="1" checked="checked"')) {
    fwrite(STDERR, "GTM opt-in setting must display checked state\n"); exit(1);
}
if (!str_contains($html, 'name="tbx_wa[track_clicks]" value="1" checked="checked"')) {
    fwrite(STDERR, "Click tracking must display checked state\n"); exit(1);
}
$GLOBALS['tracking'] = '0';
$GLOBALS['settings']['gtm_legacy'] = '0';
ob_start();
TBX_Native_Contacts::render_wa_settings();
$html = ob_get_clean();
if (!str_contains($html, 'registro individual de clics está desactivado')) {
    fwrite(STDERR, "Disabled click tracking warning missing\n"); exit(1);
}
if (str_contains($html, 'name="tbx_wa[gtm_legacy]" value="1" checked="checked"')) {
    fwrite(STDERR, "GTM toggle should be off by default\n"); exit(1);
}
$GLOBALS['form_gtm'] = '1';
ob_start();
TBX_Native_Contacts::render_settings();
$form_html = ob_get_clean();
if (!str_contains($form_html, 'name="form_gtm_compat" value="1" checked="checked"') ||
    !str_contains($form_html, 'tbx_generate_lead')) {
    fwrite(STDERR, "Form GTM compatibility toggle must render enabled and documented\n"); exit(1);
}
$GLOBALS['form_gtm'] = '0';
ob_start();
TBX_Native_Contacts::render_settings();
$form_html = ob_get_clean();
if (str_contains($form_html, 'name="form_gtm_compat" value="1" checked="checked"')) {
    fwrite(STDERR, "Form GTM compatibility toggle must default off\n"); exit(1);
}

// La captura se puede preparar con el plugin antiguo activo sin registrar
// duplicados, de modo que el corte controlado no deje una ventana ciega.
$GLOBALS['native'] = '1';
$GLOBALS['form_gtm'] = '1';
$GLOBALS['logged'] = false;
if (!TBX_Native_Contacts::can_capture() || !TBX_Native_Contacts::can_emit_form_gtm()) {
    fwrite(STDERR, "Native capture should work before detecting legacy\n"); exit(1);
}
eval('function tbx_leads_insert($data) { return 1; }');
if (TBX_Native_Contacts::can_capture() || TBX_Native_Contacts::can_emit_form_gtm()) {
    fwrite(STDERR, "Prearmed capture must NOT run while TIBOX Leads is active\n"); exit(1);
}
ob_start();
TBX_Native_Contacts::render_settings();
$form_html = ob_get_clean();
if (!str_contains($form_html, 'name="native_enabled" value="1" checked="checked"') ||
    !str_contains($form_html, 'preparar para activar') ||
    !str_contains($form_html, 'NO comenzará a registrar') ||
    str_contains($form_html, 'name="native_enabled" value="1" checked="checked" disabled')) {
    fwrite(STDERR, "Prearmed mode must be configurable and clearly guarded\n"); exit(1);
}
echo "Native contact admin settings smoke tests passed\n";
