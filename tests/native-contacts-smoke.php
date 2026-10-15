<?php
define('ABSPATH', __DIR__ . '/');
function add_action(...$args): void {}
function sanitize_text_field($s): string { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s): string { return trim(strip_tags((string) $s)); }
function sanitize_key($s): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $s)); }
function is_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL) ?: false; }
function sanitize_email($s): string { return filter_var($s, FILTER_SANITIZE_EMAIL); }
function get_option($key, $default = null) { return $key === 'tbx_contacts_native_enabled' ? '1' : $default; }
function is_user_logged_in(): bool { return false; }
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';

$raw = [
    ['n' => 'nombre', 'l' => 'Nombre', 'v' => '<b>Ana Soto</b>', 't' => 'text'],
    ['n' => 'correo', 'l' => 'Correo', 'v' => 'ana@example.cl', 't' => 'email'],
    ['n' => 'telefono', 'l' => 'Teléfono', 'v' => '+56998765432', 't' => 'tel'],
    ['n' => 'mensaje', 'l' => 'Consulta', 'v' => 'Necesito una cotización', 't' => 'textarea'],
    ['n' => 'password', 'l' => 'Clave', 'v' => 'supersecret', 't' => 'text'],
    ['n' => 'token', 'l' => 'Token', 'v' => '123', 't' => 'hidden'],
    ['n' => 'upload', 'l' => 'Adjunto', 'v' => 'archivo.pdf', 't' => 'file'],
    ['n' => '_wpcf7', 'l' => 'System', 'v' => '123', 't' => 'hidden'],
    ['n' => 'opciones', 'l' => 'Opciones', 'v' => ['uno', 'dos'], 't' => 'checkbox'],
];
$fields = TBX_Native_Contacts::normalize_fields($raw);
if (count($fields) !== 4) { fwrite(STDERR, 'Expected exactly 4 safe fields'.PHP_EOL); exit(1); }
$c = TBX_Native_Contacts::extract_contact($fields);
$expected = ['name' => 'Ana Soto', 'email' => 'ana@example.cl', 'phone' => '+56998765432', 'message' => 'Necesito una cotización'];
if ($c !== $expected) { fwrite(STDERR, 'Contact extraction mismatch: '.json_encode($c).PHP_EOL); exit(1); }
if (!TBX_Native_Contacts::can_capture()) { fwrite(STDERR, 'Native capture should be enabled without legacy'.PHP_EOL); exit(1); }
// Define legacy function only after first assertion (PHP precompiles ordinary function declarations).
eval('function tbx_leads_insert($data) { return 0; }');
if (TBX_Native_Contacts::can_capture()) { fwrite(STDERR, 'Native capture must block when legacy is present'.PHP_EOL); exit(1); }
echo "Native form capture smoke tests passed\n";
