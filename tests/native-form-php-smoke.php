<?php
/**
 * QA autónomo PHP: formularios confirmados se guardan una vez; conversiones
 * GTM opcionales no deben activarse mientras el capturador legado exista.
 */
define('ABSPATH', __DIR__ . '/');
define('TBX_ADMIN_DIR', dirname(__DIR__) . '/');
define('TBX_ADMIN_URL', 'https://prodata.cl/wp-content/plugins/tibox-admin/');
$GLOBALS['options'] = [
    'tbx_contacts_native_enabled' => '1',
    'tbx_contacts_form_gtm_compat_enabled' => '1',
    'tbx_native_contact_sequence_version' => '1.0.0',
];
$GLOBALS['scripts'] = [];
class WPDB_Form_Test {
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    public array $contacts = [];
    public int $sequence = 0;
    public bool $failContactOnce = false;
    public function insert(string $table, array $data, array $format = []) {
        if (str_ends_with($table, 'tbx_admin_contact_sequence')) {
            $this->sequence++;
            $this->insert_id = $this->sequence;
            return 1;
        }
        if ($this->failContactOnce) {
            $this->failContactOnce = false;
            return false;
        }
        $this->contacts[] = $data;
        return 1;
    }
}
$wpdb = new WPDB_Form_Test();
class TBX_Contacts {
    public static function install(): void {}
    public static function table(): string { return 'wp_tbx_admin_contacts'; }
}
class TBX_Web_Vitals {
    public static function normalize_path($value): string {
        return (string) parse_url((string) $value, PHP_URL_PATH);
    }
}
class TBX_Analytics_Context {
    public static function classify($userAgent): array {
        return ['device'=>'desktop','browser'=>'Chrome','os'=>'Windows'];
    }
}
function get_option($name, $default = null) { return $GLOBALS['options'][$name] ?? $default; }
function add_action(...$args): void {}
function add_filter(...$args): void {}
function is_user_logged_in(): bool { return false; }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value): string { return trim(strip_tags((string) $value)); }
function sanitize_email($value): string { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_key($value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string)$value)); }
function mb_substr_fake($str,$begin,$len) { return substr($str,$begin,$len); }
function wp_get_referer() { return 'https://prodata.cl/contacto/?utm_source=google&utm_medium=cpc'; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function home_url($path = '') { return 'https://prodata.cl' . $path; }
function wp_unslash($value) { return $value; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function current_time($format, $gmt = false) { return '2026-10-15 19:00:00'; }
function wp_doing_ajax(): bool { return true; }
function wp_enqueue_script($handle, ...$args) { $GLOBALS['scripts'][] = $handle; }
function wp_add_inline_script($handle,$script,$position) {
    $GLOBALS['inline_scripts'][$handle] = $script;
}
function rest_url($path) { return 'https://prodata.cl/wp-json/' . $path; }
function esc_url_raw($value) { return $value; }
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';
function check_form(bool $ok, string $msg): void {
    if (!$ok) { fwrite(STDERR, $msg . PHP_EOL); exit(1); }
}

check_form(TBX_Native_Contacts::can_capture(), 'Form capture must be ready');
check_form(TBX_Native_Contacts::can_emit_form_gtm(), 'GTM compatibility should be ready when opted-in');
TBX_Native_Contacts::enqueue_form_gtm();
check_form(in_array('tbx-native-form-gtm-compat', $GLOBALS['scripts'], true), 'GTM JS must enqueue');
check_form(str_contains($GLOBALS['inline_scripts']['tbx-native-form-gtm-compat'] ?? '', '"enabled":true'), 'GTM compat must be configured explicitly');

$fields = [
 ['id'=>'1','name'=>'Nombre','value'=>'Ana López','type'=>'text'],
 ['id'=>'2','name'=>'Correo','value'=>'ana@example.cl','type'=>'email'],
 ['id'=>'3','name'=>'Clave','value'=>'password-privado','type'=>'password'],
];
$data = ['id'=>'830','settings'=>['form_title'=>'Cotización']];
TBX_Native_Contacts::wpforms($fields, [], $data, '102');
check_form(count($wpdb->contacts) === 1, 'WPForms confirmed submission must be stored');
check_form($wpdb->contacts[0]['type'] === 'form' && $wpdb->contacts[0]['form_id'] === '830', 'WPForms metadata missing');
check_form($wpdb->contacts[0]['source'] === 'google', 'UTM attribution lost');
check_form(!str_contains($wpdb->contacts[0]['fields'], 'password-privado'), 'Sensitive fields must not be recorded');
TBX_Native_Contacts::wpforms($fields, [], $data, '102');
check_form(count($wpdb->contacts) === 1, 'Duplicate same-request WPForms hook must not store twice');

// Captura independiente de la activación de GTM: apagar la compatibilidad
// no debe apagar los leads válidos.
$GLOBALS['options']['tbx_contacts_form_gtm_compat_enabled'] = '0';
check_form(!TBX_Native_Contacts::can_emit_form_gtm(), 'GTM opt-out must be honored');
$data['id'] = '10646';
TBX_Native_Contacts::wpforms($fields, [], $data, '103');
check_form(count($wpdb->contacts) === 2, 'Native capture must still save forms with GTM compat OFF');

// Si la base de datos falla una vez, NO se debe marcar el envío como
// procesado exitosamente. Una reejecución del hook en la misma solicitud
// puede recuperarse sin perder el contacto y sin duplicar una inserción previa.
$data['id'] = '19597';
$wpdb->failContactOnce = true;
TBX_Native_Contacts::wpforms($fields, [], $data, '105');
check_form(count($wpdb->contacts) === 2, 'Failed DB insertion cannot count as saved contact');
TBX_Native_Contacts::wpforms($fields, [], $data, '105');
check_form(count($wpdb->contacts) === 3, 'Retry after transient DB error must succeed exactly once');
TBX_Native_Contacts::wpforms($fields, [], $data, '105');
check_form(count($wpdb->contacts) === 3, 'Successful retry must be deduplicated');

// Rehabilitar GTM en el controlador no altera la captura persistida.
$GLOBALS['options']['tbx_contacts_form_gtm_compat_enabled'] = '1';

// En presencia de TIBOX Leads no deben emitirse eventos ni insertar duplicados.
eval('function tbx_leads_insert($row) { return 1; }');
check_form(!TBX_Native_Contacts::can_capture(), 'Legacy should block native forms');
check_form(!TBX_Native_Contacts::can_emit_form_gtm(), 'Legacy should block native GTM compat');
$GLOBALS['scripts'] = [];
TBX_Native_Contacts::enqueue_form_gtm();
check_form(!$GLOBALS['scripts'], 'Legacy should prevent loading GTM compat script');
$data['id'] = '9918';
TBX_Native_Contacts::wpforms($fields, [], $data, '104');
check_form(count($wpdb->contacts) === 3, 'Legacy coexistence must not double insert');
echo "Native WPForms capture and legacy GTM gating smoke tests passed\n";
