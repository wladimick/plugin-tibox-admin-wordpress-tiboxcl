<?php
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');
function add_action(...$a) {}
function get_option($key, $default = null) { return $default; }
function wp_json_encode($v) { return json_encode($v); }
function sanitize_key($v) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$v)); }
function sanitize_text_field($s) { return (string)$s; }
function wp_unslash($s) { return $s; }
function absint($v) { return abs((int)$v); }
function current_time($fmt, $gmt = false) { return '2026-10-15 18:00:00'; }
class TBX_Admin { public static function register_tab(...$a) {} }
class FakeDB {
    public string $prefix = 'wp_';
    public array $records = [
        1 => ['id'=>1,'source_plugin'=>'tibox-leads','legacy_id'=>41],
        2 => ['id'=>2,'source_plugin'=>'tibox-admin-native','legacy_id'=>5],
    ];
    public array $deleted = [];
    public bool $marker_fails = false;
    public function esc_like(string $s): string { return $s; }
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $token = strpos($sql, '%d') < strpos($sql,'%s') || strpos($sql,'%s') === false ? '%d' : '%s';
            if (strpos($sql, $token) === false) $token = strpos($sql,'%s') !== false ? '%s' : '%d';
            $replacement = $token === '%d' ? (string)(int)$arg : "'".str_replace("'","''",(string)$arg)."'";
            $pos = strpos($sql,$token);
            if ($pos !== false) $sql = substr_replace($sql,$replacement,$pos,2);
        }
        return $sql;
    }
    public function get_var(string $sql) {
        if (str_starts_with($sql,'SHOW TABLES LIKE ')) {
            return trim(substr($sql,17)," '");
        }
        if (str_contains($sql,'tbx_admin_contact_deleted')) {
            preg_match('/legacy_id = ([0-9]+)/',$sql,$m);
            $id=(int)($m[1]??0);
            return isset($this->deleted[$id])?$id:null;
        }
        return null;
    }
    public function get_row(string $sql, $output) {
        preg_match('/WHERE id=([0-9]+)/',$sql,$m);
        return $this->records[(int)($m[1]??0)] ?? null;
    }
    public function query(string $sql) {
        if ($this->marker_fails) return false;
        if (str_contains($sql,'tbx_admin_contact_deleted')) {
            preg_match('/VALUES \\([^,]+,([0-9]+),/', $sql, $m);
            $id=(int)($m[1]??0);
            $this->deleted[$id]=true;
            return 1;
        }
        return 0;
    }
    public function delete(string $table, array $where, array $fmt) {
        if (!isset($this->records[$where['id']])) return 0;
        unset($this->records[$where['id']]);
        return 1;
    }
}
$wpdb = new FakeDB();
require_once dirname(__DIR__) . '/includes/modules/contacts/module.php';
function check($ok, string $message): void { if (!$ok) { fwrite(STDERR, $message."\n"); exit(1); }}
check(TBX_Contacts::delete_contact(1) === 'deleted','Legacy delete must succeed');
check(!isset($wpdb->records[1]),'Legacy copy should be gone');
check(isset($wpdb->deleted[41]),'Legacy ID must be tombstoned');
check(TBX_Contacts::is_suppressed('tibox-leads',41),'Tombstoned contact should never reimport');
check(TBX_Contacts::delete_contact(2) === 'deleted','Native contact delete must succeed');
check(count($wpdb->deleted) === 1,'Native deletion must not add a legacy tombstone');
check(TBX_Contacts::delete_contact(999) === 'not_found','Missing ID should not report success');
$wpdb->records[3]=['id'=>3,'source_plugin'=>'tibox-leads','legacy_id'=>42];
$wpdb->marker_fails=true;
check(TBX_Contacts::delete_contact(3) === 'error','Tombstone write failure must abort delete');
check(isset($wpdb->records[3]),'Historical copy must survive failed tombstone');
foreach (['=1+2', ' +SUM(A1)', '-1+2', '@HYPERLINK', "\t=SUM(1)"] as $formula) {
    check(str_starts_with(TBX_Contacts::csv_safe($formula),"'"),'CSV injection not escaped');
}
check(TBX_Contacts::csv_safe('Hebert')==='Hebert','Plain cells must remain intact');
check(TBX_Contacts::csv_safe('google.com / organic')==='google.com / organic','Campaign data must not change');
echo "Contact CSV safety and deletion/tombstone tests passed\n";
