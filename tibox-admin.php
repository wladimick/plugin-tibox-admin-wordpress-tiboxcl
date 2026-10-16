<?php
/**
 * Plugin Name:       Tibox Admin
 * Description:       Panel único de Tibox: landings, leads, actividad del sitio y slider. Reemplaza varios snippets de WPCode con módulos que se activan por separado.
 * Version:           1.10.4
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Tibox · Equipo de Diseño
 * Text Domain:       tibox-admin
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TBX_ADMIN_VERSION', '1.10.4');
define('TBX_ADMIN_FILE', __FILE__);
define('TBX_ADMIN_DIR', plugin_dir_path(__FILE__));
define('TBX_ADMIN_URL', plugin_dir_url(__FILE__));
define('TBX_ADMIN_SLUG', 'tibox-admin');

require_once TBX_ADMIN_DIR . 'includes/class-tbx-analytics-context.php';
require_once TBX_ADMIN_DIR . 'includes/class-tbx-modules.php';
require_once TBX_ADMIN_DIR . 'includes/class-tbx-admin.php';

/*
 * Los módulos se cargan en after_setup_theme: a esa altura WPCode ya ejecutó
 * sus snippets, así que se puede detectar si el snippet equivalente sigue
 * activo y, en ese caso, no cargar el módulo (evita duplicar clases).
 */
add_action('after_setup_theme', ['TBX_Modules', 'boot'], 20);
add_action('plugins_loaded', ['TBX_Admin', 'init']);

register_activation_hook(__FILE__, function () {
    TBX_Modules::on_activate();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('tbx_activity_purge');
});
