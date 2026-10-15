<?php
define('ABSPATH', __DIR__ . '/');
function wp_parse_url($value, $component) {
    return parse_url($value, $component);
}
function add_action(...$args) { return null; }
require_once dirname(__DIR__) . '/includes/modules/activity/class-tbx-web-vitals.php';

$cases = [
    ['LCP', 2500.0, 'good'],
    ['LCP', 3200.0, 'improve'],
    ['LCP', 4500.0, 'poor'],
    ['CLS', .1, 'good'],
    ['CLS', .2, 'improve'],
    ['CLS', .3, 'poor'],
    ['INP', 200, 'good'],
    ['INP', 300, 'improve'],
    ['INP', 700, 'poor'],
    ['INP', -10, null],
    ['LCP', INF, null],
    ['unknown', 5, null],
];
foreach ($cases as [$metric, $value, $expected]) {
    $actual = TBX_Web_Vitals::rating($metric, (float) $value);
    if ($actual !== $expected) {
        fwrite(STDERR, "Rating mismatch: {$metric}={$value}\n");
        exit(1);
    }
}
echo "12 Web Vitals rating test cases passed\n";
