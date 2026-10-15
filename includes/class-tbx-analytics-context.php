<?php
/**
 * Clasificación de agentes de usuario para analítica agregada.
 * No persiste el User-Agent original, IP, IDs de sesión ni fingerprints.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Analytics_Context
{
    public static function classify(string $ua): array
    {
        $device = 'desktop';
        if ($ua === '' || preg_match('/bot|spider|crawler|headless/i', $ua)) {
            return ['device' => 'unknown', 'browser' => 'Other', 'os' => 'Other'];
        }
        if (preg_match('/iPad|Tablet|PlayBook|Silk\/|Kindle|Android(?!.*Mobile)/i', $ua)) {
            $device = 'tablet';
        } elseif (preg_match('/Mobile|iPhone|iPod|Android|Windows Phone/i', $ua)) {
            $device = 'mobile';
        }

        $browser = 'Other';
        if (preg_match('/Edg(?:e|A|iOS)?\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\/|Opera\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/SamsungBrowser\//i', $ua)) {
            $browser = 'Samsung';
        } elseif (preg_match('/Firefox\/|FxiOS\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Chrome\/|CriOS\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        }

        $os = 'Other';
        if (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Windows/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $os = 'ChromeOS';
        } elseif (preg_match('/Mac OS X|Macintosh/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        return compact('device', 'browser', 'os');
    }
}
