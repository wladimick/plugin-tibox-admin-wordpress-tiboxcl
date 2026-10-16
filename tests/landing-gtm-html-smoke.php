<?php
/**
 * QA: una landing con documento HTML completo omite wp_head/wp_footer.
 * Inyección opt-in del puente GETM y exclusión mutua con TIBOX Leads.
 */
define('ABSPATH', __DIR__ . '/');
define('TBX_ADMIN_URL', 'https://tibox.cl/wp-content/plugins/tibox-admin/');
define('TBX_ADMIN_VERSION', '1.10.5');
$GLOBALS['options'] = [
    'tbx_contacts_native_enabled' => '1',
    'tbx_contacts_form_gtm_compat_enabled' => '1',
];
$GLOBALS['logged'] = false;
function add_action(...$args): void {}
function add_filter(...$args): void {}
function get_option($name, $default = null) {
    return $GLOBALS['options'][$name] ?? $default;
}
function is_user_logged_in(): bool { return $GLOBALS['logged']; }
function add_query_arg($key, $value, $url) { return $url . '?'. rawurlencode($key) . '=' . rawurlencode($value); }
function esc_url($url) { return $url; }
require_once dirname(__DIR__) . '/includes/modules/contacts/class-tbx-native-capture.php';
require_once dirname(__DIR__) . '/includes/modules/landings/class-landing-manager.php';

function expect_ok($ok,$reason): void {
    if (!$ok) {fwrite(STDERR,$reason . PHP_EOL);exit(1);}
}

$html = '<!DOCTYPE html><html><head><title>Diagnóstico</title></head><body><main>Sin alterar</main></body></html>';
$enhanced = TIBOX_Landing_Manager::inject_native_gtm_bridge($html);
expect_ok(substr_count($enhanced, 'id="tbx-landing-lead-bridge"') === 1,
    'Exactly one native landing conversion bridge should load');
expect_ok(str_contains($enhanced,'assets/tibox-landing-lead-bridge.js'),
    'Bridge script must resolve from plugin');
expect_ok(str_contains($enhanced,'window.TBX_LANDING_GTM_COMPAT={"enabled":true};'),
    'Bridge opt-in JS settings missing');
expect_ok(strpos($enhanced,'tbx-landing-lead-bridge') < strpos($enhanced,'</head>'),
    'Bridge must be loaded in head before body scripts');
expect_ok(str_contains($enhanced,'<main>Sin alterar</main>'),
    'Do not modify landing body markup');
$again = TIBOX_Landing_Manager::inject_native_gtm_bridge($enhanced);
expect_ok($again === $enhanced, 'A duplicate injection must be idempotent');

$withoutHead = '<html><body><p>Sitio</p></body></html>';
$fallback = TIBOX_Landing_Manager::inject_native_gtm_bridge($withoutHead);
expect_ok(strpos($fallback,'tbx-landing-lead-bridge') < strpos($fallback,'</body>'),
    'Landing without head should inject before closing body');

$GLOBALS['options']['tbx_contacts_form_gtm_compat_enabled'] = '0';
expect_ok(TIBOX_Landing_Manager::inject_native_gtm_bridge($html) === $html,
    'Default disabled conversion compat cannot alter landing HTML');
$GLOBALS['options']['tbx_contacts_form_gtm_compat_enabled'] = '1';
$GLOBALS['options']['tbx_contacts_native_enabled'] = '0';
expect_ok(TIBOX_Landing_Manager::inject_native_gtm_bridge($html) === $html,
    'Form capture off cannot inject conversion tracking');
$GLOBALS['options']['tbx_contacts_native_enabled'] = '1';
$GLOBALS['logged'] = true;
expect_ok(TIBOX_Landing_Manager::inject_native_gtm_bridge($html) === $html,
    'Logged in admins should not contaminate test conversions');
$GLOBALS['logged'] = false;
eval('function tbx_leads_insert($data) { return 1; }');
expect_ok(TIBOX_Landing_Manager::inject_native_gtm_bridge($html) === $html,
    'Old plugin must exclusively handle legacy conversions');

echo "Full HTML landing conversion opt-in injection smoke tests passed\n";
