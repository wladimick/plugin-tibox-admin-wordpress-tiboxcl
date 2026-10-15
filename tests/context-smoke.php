<?php
/* Smoke tests autocontenidos; no requieren WordPress instalado. */
define('ABSPATH', __DIR__ . '/');
require_once dirname(__DIR__) . '/includes/class-tbx-analytics-context.php';

$cases = [
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 17_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) CriOS/121.0 Mobile/15E148 Safari/604.1', 'mobile', 'Chrome', 'iOS'],
    ['Mozilla/5.0 (Linux; Android 14; SM-S911B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/23.0 Chrome/110.0 Mobile Safari/537.36', 'mobile', 'Samsung', 'Android'],
    ['Mozilla/5.0 (iPad; CPU OS 17_1 like Mac OS X) AppleWebKit/605.1.15 Version/17.1 Mobile Safari/604.1', 'tablet', 'Safari', 'iOS'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/126.0 Safari/537.36', 'desktop', 'Chrome', 'Windows'],
    ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15', 'desktop', 'Safari', 'macOS'],
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36 Edg/124.0', 'desktop', 'Edge', 'Windows'],
    ['Googlebot/2.1', 'unknown', 'Other', 'Other'],
];
foreach ($cases as [$ua, $device, $browser, $os]) {
    $actual = TBX_Analytics_Context::classify($ua);
    if ($actual !== compact('device', 'browser', 'os')) {
        fwrite(STDERR, 'Context mismatch: ' . json_encode($actual) . PHP_EOL);
        exit(1);
    }
}
echo "7 device/browser/OS smoke cases passed\n";
