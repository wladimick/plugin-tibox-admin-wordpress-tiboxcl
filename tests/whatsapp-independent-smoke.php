<?php
// Prueba aislada: dos plugins instalados, formulario nativo apagado.
define('ABSPATH', __DIR__ . '/');
define('TBX_ADMIN_DIR', dirname(__DIR__) . '/');
define('TBX_ADMIN_URL', 'https://prodata.cl/wp-content/plugins/tibox-admin/');
define('TBX_ADMIN_VERSION', '1.9.1');
$GLOBALS['is_admin_screen'] = false;
$GLOBALS['wa_settings'] = [
    'enabled' => '1', 'number' => '56912345678',
    'message' => 'Hola desde {sitio}, página {pagina}',
    'label' => 'Cotiza con nosotros', 'side' => 'left',
];
$GLOBALS['styles'] = [];
$GLOBALS['scripts'] = [];
$GLOBALS['inline'] = [];
function add_action(...$args) {}
function add_filter(...$args) {}
function get_option($name, $default = null) {
    return match ($name) {
        'tbx_admin_contact_whatsapp' => $GLOBALS['wa_settings'],
        'tbx_contacts_native_enabled' => '0',
        'tbx_contacts_click_tracking_enabled' => '1',
        default => $default
    };
}
function is_user_logged_in() { return true; }
function is_admin() { return $GLOBALS['is_admin_screen']; }
function tbx_leads_insert($row) { return 1; } // TIBOX Leads activo.
function wp_enqueue_style($name, ...$args) { $GLOBALS['styles'][] = $name; }
function wp_enqueue_script($name, ...$args) { $GLOBALS['scripts'][] = $name; }
function wp_add_inline_script($handle, $code, $position) {
    $GLOBALS['inline'][$handle] = [$code,$position];
}
function wp_json_encode($value) { return json_encode($value); }
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';
function ensure($pass, $why) {
    if (!$pass) { fwrite(STDERR, $why . PHP_EOL); exit(1); }
}
ensure(TBX_Native_Contacts::legacy_active(), 'Legacy plugin is required to be active');
ensure(!TBX_Native_Contacts::can_capture(), 'Native form capture must remain disabled');
ensure(!TBX_Native_Contacts::can_capture_clicks(), 'Native click recording must be blocked while legacy is active');
ensure(TBX_Native_Contacts::wa_button_enabled(), 'WA button should be enabled independently');
TBX_Native_Contacts::enqueue_clicks();
ensure(!$GLOBALS['scripts'], 'Native tracking JS must not load with legacy');
TBX_Native_Contacts::enqueue_wa_button();
ensure(in_array('tbx-independent-wa-button', $GLOBALS['scripts'], true), 'Independent WA button JS was not enqueued');
ensure(in_array('tbx-native-contact-button', $GLOBALS['styles'], true), 'Floating button stylesheet was not enqueued');
ensure(str_contains($GLOBALS['inline']['tbx-independent-wa-button'][0], '56912345678'), 'WA number config missing');

$old = ['wa_on'=>1, 'wa_number'=>'56999999999','wa_message'=>'Mensaje antiguo','unify'=>1];
$filtered = TBX_Native_Contacts::legacy_whatsapp_frontend($old);
ensure($filtered['wa_on'] === 0, 'Legacy floating button must be suppressed at runtime');
ensure($filtered['wa_number'] === '56912345678', 'Legacy tracking must use new TIBOX button number');
ensure($filtered['wa_message'] === $GLOBALS['wa_settings']['message'], 'Legacy tracking must use new message');
ensure($filtered['wa_ref'] === 1, 'Legacy tracking must keep reference codes');
ensure($filtered['unify'] === 0, 'Legacy site-wide WhatsApp unification must follow new option');
ensure($old['wa_on'] === 1 && $old['wa_number'] === '56999999999',
    'Legacy saved settings cannot be modified');

$GLOBALS['is_admin_screen'] = true;
ensure(TBX_Native_Contacts::legacy_whatsapp_frontend($old) === $old,
    'WordPress dashboard must show original legacy settings');
$GLOBALS['is_admin_screen'] = false;
$GLOBALS['wa_settings']['enabled'] = '0';
ensure(!TBX_Native_Contacts::wa_button_enabled(), 'WA button must respect disabled setting');
ensure(TBX_Native_Contacts::legacy_whatsapp_frontend($old) === $old,
    'Old plugin should control its button when TIBOX Admin WA button is disabled');
$GLOBALS['scripts'] = [];
TBX_Native_Contacts::enqueue_wa_button();
ensure(!$GLOBALS['scripts'], 'No WhatsApp script should load when button is disabled');
echo "Independent WhatsApp button + legacy tracking coexistence tests passed\n";
