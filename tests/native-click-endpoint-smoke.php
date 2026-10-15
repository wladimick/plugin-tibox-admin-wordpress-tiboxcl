<?php
define('ABSPATH', __DIR__ . '/');
class WP_REST_Response {
    public array $data; public int $status;
    public function __construct(array $data, int $status) { $this->data = $data; $this->status = $status; }
}
class WP_REST_Request {
    private string $json; private array $headers;
    public function __construct(array $body, array $headers) {
        $this->json = json_encode($body);
        $this->headers = $headers;
    }
    public function get_header(string $key) { return $this->headers[$key] ?? ''; }
    public function get_body(): string { return $this->json; }
}
class TBX_Contacts {
    public static function install(): void {}
    public static function table(): string { return 'wp_tbx_admin_contacts'; }
}
class TBX_Web_Vitals {
    public static function normalize_path($v): string { return (string) parse_url((string)$v, PHP_URL_PATH); }
}
class TBX_Analytics_Context {
    public static function classify(string $v): array {
        return ['device'=>'mobile','browser'=>'Chrome','os'=>'Android'];
    }
}
class FakeDB {
    public int $insert_id = 0;
    public array $rows = [];
    public function insert(string $table, array $data, array $formats = []) {
        if (str_ends_with($table, '_sequence')) {
            $this->insert_id++;
        } else {
            $this->rows[] = $data;
        }
        return 1;
    }
}
$wpdb = new FakeDB();
function add_action(...$args): void {}
function add_filter(...$args) {}
function get_option($k, $default = null) {
    return match($k) {
        'tbx_contacts_native_enabled' => '1',
        'tbx_native_contact_sequence_version' => '1.0.0',
        default => $default,
    };
}
function is_user_logged_in(): bool { return false; }
function home_url(string $p = ''): string { return 'https://prodata.cl' . $p; }
function wp_parse_url(string $s, int $part) { return parse_url($s, $part); }
function sanitize_text_field($s): string { return trim(strip_tags((string)$s)); }
function sanitize_key($s): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)$s)); }
function sanitize_email($s): string { return filter_var((string)$s, FILTER_SANITIZE_EMAIL); }
function wp_json_encode($s) { return json_encode($s); }
function current_time($format, $gmt = false) { return '2026-10-15 17:00:00'; }
function wp_salt($name) { return 'test-salt'; }
function get_transient($key) { return 0; }
function set_transient($key, $value, $expires) { return true; }
define('MINUTE_IN_SECONDS', 60);
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';

$headers = ['origin' => 'https://prodata.cl', 'user_agent' => 'Mozilla/5.0 (Linux; Android 14) Chrome/126.0'];
$body = [
    'type' => 'whatsapp', 'ref' => 'ABC234', 'destination' => '56912345678',
    'button_text' => 'Cotizar', 'location' => 'header',
    'path' => '/lp/porticos/', 'campaign' => ['source'=>'google','medium'=>'cpc'],
];
$response = TBX_Native_Contacts::receive_click(new WP_REST_Request($body, $headers));
if ($response->status !== 200 || count($wpdb->rows) !== 1) {
    fwrite(STDERR, "Expected one saved WhatsApp click\n"); exit(1);
}
$entry = $wpdb->rows[0];
if ($entry['ref_code'] !== 'ABC234' || $entry['device'] !== 'mobile' ||
    $entry['source_plugin'] !== 'tibox-admin-native' ||
    $entry['type'] !== 'whatsapp' || $entry['source'] !== 'google') {
    fwrite(STDERR, 'Saved click mismatch: '.json_encode($entry).PHP_EOL); exit(1);
}
if (isset($entry['visitor_id']) || isset($entry['user_agent']) || isset($entry['ip_hash'])) {
    fwrite(STDERR, "Sensitive visitor data must not be stored\n"); exit(1);
}
$invalid = TBX_Native_Contacts::receive_click(new WP_REST_Request(
    array_merge($body,['type'=>'payment']), $headers
));
if ($invalid->status !== 400 || count($wpdb->rows) !== 1) { fwrite(STDERR, "Invalid event must be rejected\n"); exit(1); }
$wrong_origin = TBX_Native_Contacts::receive_click(new WP_REST_Request(
    $body, ['origin'=>'https://elsewhere.cl','user_agent'=>$headers['user_agent']]
));
if ($wrong_origin->status !== 403 || count($wpdb->rows) !== 1) { fwrite(STDERR, "External origin must be rejected\n"); exit(1); }
echo "Native click REST endpoint smoke tests passed\n";
