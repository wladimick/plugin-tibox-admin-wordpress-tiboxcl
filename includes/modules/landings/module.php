<?php
/**
 * Módulo Landings: gestor de landings + importador ZIP.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/class-landing-manager.php';
require_once __DIR__ . '/class-landing-zip-importer.php';

TBX_Admin::register_tab(
    'landings',
    'Landings',
    null,
    10,
    admin_url('edit.php?post_type=tibox_landing')
);

add_filter('tbx_admin_dashboard_cards', function (array $cards): array {
    $counts = wp_count_posts('tibox_landing');
    $published = isset($counts->publish) ? (int) $counts->publish : 0;

    $active = get_posts([
        'post_type' => 'tibox_landing',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_key' => '_tibox_lp_ads_active',
        'meta_value' => '1',
        'no_found_rows' => true,
    ]);

    $cards[] = [
        'value' => $published,
        'label' => 'Landings publicadas',
        'note' => count($active) . ' con campaña activa',
        'url' => admin_url('edit.php?post_type=tibox_landing'),
    ];

    return $cards;
}, 10);
