<?php
/**
 * Smoke test real mapping paths for historic WPForms + landing imports.
 * WordPress functions are stubbed; no database or production data required.
 */
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

function add_action(...$args): void {}
function add_filter(...$args) {}
function get_option($key, $default = null) { return $default; }
function is_user_logged_in(): bool { return false; }
function sanitize_text_field($v): string { return trim(strip_tags((string) $v)); }
function sanitize_textarea_field($v): string { return trim(strip_tags((string) $v)); }
function sanitize_key($v): string { return strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string) $v)); }
function sanitize_email($v): string { return filter_var((string) $v, FILTER_SANITIZE_EMAIL); }
function is_email($v) { return filter_var($v, FILTER_VALIDATE_EMAIL); }
function absint($v): int { return abs((int) $v); }
function current_time($v, $gmt = false): string { return '2026-10-15 19:10:00'; }
function get_gmt_from_date($v): string { return (string) $v . ' UTC'; }
function wp_json_encode($v, $options = 0): string { return json_encode($v, $options); }
function wp_parse_url($url, $part) { return parse_url($url, $part); }
function home_url($p = '/'): string { return 'https://prodata.cl' . $p; }
function get_the_title($id): string { return $id === 830 ? 'Nuevo Lead Prodata' : 'Landing'; }

class TBX_Contacts
{
    public static array $suppressed = [];
    public static function is_suppressed(string $src, int $id): bool {
        return isset(self::$suppressed[$src . ':' . $id]);
    }
    public static function suppress_external(string $src, int $id): bool {
        self::$suppressed[$src . ':' . $id] = true;
        return true;
    }
    public static function table(): string { return 'wp_tbx_admin_contacts'; }
    public static function suppressed_table(): string { return 'wp_tbx_admin_contact_deleted'; }
    public static function install(): void {}
}
class DummyDB
{
    public string $prefix = 'wp_';
    public array $saved = [];
    public bool $legacy_dupe = false;
    public array $entries = [
        1 => [
            'entry_id'=>1,'form_id'=>830, 'type'=>'entry', 'status'=>'publish',
            'date'=>'2026-10-14 10:50:00',
            'fields'=>'{"1":{"id":1,"name":"Nombre","type":"name","value":"Hebert Bezemer"},' .
                '"2":{"id":2,"name":"Email","type":"email","value":"hbezemer@ejemplo.cl"},' .
                '"3":{"id":3,"name":"Clave","type":"password","value":"confidencial"}}'
        ],
        2 => [
            'entry_id'=>2,'form_id'=>830,'type'=>'entry','status'=>'publish',
            'date'=>'2026-10-14 10:51:00',
            'fields'=>'{"1":{"id":1,"name":"Correo","type":"email","value":"hbezemer@ejemplo.cl"}}'
        ],
        3 => [
            'entry_id'=>3,'form_id'=>830,'type'=>'entry','status'=>'spam',
            'date'=>'2026-10-14 10:52:00',
            'fields'=>'{"1":{"id":1,"name":"Correo","type":"email","value":"bot@ejemplo.cl"}}'
        ],
    ];
    public array $landings = [
        10 => [
            'id'=>10,'created_at'=>'2026-10-14 12:00:00','form_id'=>'lp-porticos',
            'name'=>'María Soto','email'=>'maria@ejemplo.cl','phone'=>'56912345678',
            'page_title'=>'Pórticos', 'landing_url'=>'https://prodata.cl/lp/porticos/?utm_source=google',
            'company'=>'Colegio Norte','rut'=>'76543210-1', 'area'=>'Educación',
            'message'=>'Quiero cotizar', 'utm_source'=>'google', 'utm_medium'=>'cpc',
            'utm_campaign'=>'porticos'
        ],
    ];
    public function esc_like($s) { return $s; }
    public function prepare($sql, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function($match) use (&$i, $args) {
            $value = $args[$i++] ?? '';
            return $match[0] === '%d' ? (string) ((int)$value) : "'" . str_replace("'","''",(string)$value) . "'";
        }, $sql);
    }
    public function get_var($sql) {
        if (str_starts_with($sql, 'SHOW TABLES')) {
            if (str_contains($sql, 'wpforms_entries')) return 'wp_wpforms_entries';
            if (str_contains($sql, 'tibox_landing_leads')) return 'wp_tibox_landing_leads';
        }
        if (str_contains($sql, 'SELECT id FROM wp_tbx_admin_contacts')) {
            if (str_contains($sql, "'tibox-leads'")) return $this->legacy_dupe ? 55 : null;
            if (preg_match("/source_plugin = '([^']+)' AND legacy_id = ([0-9]+)/", $sql, $m)) {
                foreach ($this->saved as $record) {
                    if ($record['source_plugin'] === $m[1] && $record['legacy_id'] == $m[2]) return 1;
                }
            }
        }
        return null;
    }
    public function get_row($sql, $format) {
        if (str_contains($sql, 'wp_wpforms_entries')) {
            preg_match('/entry_id = ([0-9]+)/', $sql, $m);
            return $this->entries[(int) ($m[1] ?? 0)] ?? null;
        }
        preg_match('/WHERE id = ([0-9]+)/', $sql, $m);
        return $this->landings[(int) ($m[1] ?? 0)] ?? null;
    }
    public function insert($table, $data) {
        $this->saved[] = $data;
        return 1;
    }
}
$wpdb = new DummyDB();
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-contact-sources.php';

$method = new ReflectionMethod('TBX_Contact_Sources', 'import_one');
$import = static fn($source, $id) => $method->invoke(null, $source, $id);
function expect(bool $c, string $what): void { if (!$c) { fwrite(STDERR, $what . PHP_EOL); exit(1); }}

expect($import('wpforms-history', 1), 'WPForms entry not imported');
expect(count($wpdb->saved) === 1, 'Should have exactly one entry');
expect($wpdb->saved[0]['name'] === 'Hebert Bezemer', 'Name mapping incorrect');
expect($wpdb->saved[0]['form_name'] === 'Nuevo Lead Prodata', 'WPForms title missing');
expect(!str_contains($wpdb->saved[0]['fields'], 'confidencial'), 'Password should never be imported');
expect(!$import('wpforms-history', 1), 'Importing twice must be idempotent');
expect(!$import('wpforms-history', 3), 'Spam must not be imported');

$wpdb->legacy_dupe = true;
expect($import('wpforms-history', 2), 'Duplicate legacy submission should be marked as handled');
expect(TBX_Contacts::is_suppressed('wpforms-history', 2), 'Cross-plugin duplication must be marked');
expect(count($wpdb->saved) === 1, 'Duplicate should not be copied');
$wpdb->legacy_dupe = false;

expect($import('tibox-landing', 10), 'TIBOX landing import must succeed');
expect(count($wpdb->saved) === 2, 'Expected both sources in one inbox');
expect($wpdb->saved[1]['origin'] === 'landing_ai', 'Landing origin wrong');
expect($wpdb->saved[1]['source'] === 'google', 'Landing campaign missing');
expect($wpdb->saved[1]['page_url'] === 'https://prodata.cl/lp/porticos', 'URL query must be stripped');
expect($wpdb->saved[1]['created_at'] === '2026-10-14 12:00:00 UTC', 'Landing date normalization missing');
echo "Unified WPForms/landings import and cross-source idempotence smoke tests passed\n";
