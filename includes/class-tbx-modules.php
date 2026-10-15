<?php
/**
 * Registro de módulos: cuáles existen, cuáles están activos y cuáles
 * no se pudieron cargar porque su snippet de WPCode sigue activo.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Modules
{
    public const OPTION = 'tbx_admin_modules';

    /** @var array<string,string> módulo => motivo por el que no cargó */
    private static array $blocked = [];

    /** @var string[] */
    private static array $loaded = [];

    /**
     * Definición de módulos.
     * - conflict: clase o función que define el snippet antiguo.
     * - snippets: nombres en WPCode que hay que desactivar antes.
     */
    public static function all(): array
    {
        return [
            'landings' => [
                'name' => 'Landings',
                'desc' => 'Tipo de contenido Landing (/lp/), modos de renderizado, control de campañas de Google Ads e importador ZIP de Claude Design.',
                'file' => 'modules/landings/module.php',
                'conflict' => ['class' => 'TIBOX_Landing_Manager'],
                'snippets' => [
                    'TIBOX — Gestor de Landing Pages v2 (fix: compatibilidad con Claude Design)',
                    'TIBOX — Importador ZIP Claude Design',
                ],
            ],
            'leads' => [
                'name' => 'Leads y formularios',
                'desc' => 'Endpoint /wp-json/tibox/v1/lead, registro local de leads, correos interno y de confirmación, y envío a WebOps.',
                'file' => 'modules/leads/module.php',
                'conflict' => ['class' => 'TIBOX_Landing_Form_API'],
                'snippets' => [
                    'TIBOX — Endpoint universal de formularios + almacenamiento local de Leads',
                    'TIBOX — WebOps Marketing Lead Bridge',
                ],
            ],
            'activity' => [
                'name' => 'Actividad del sitio',
                'desc' => 'Mide clics en teléfono, correo, WhatsApp, portal cliente, botones, formularios, FAQ, secciones y scroll. Envía a GTM y guarda un resumen propio en WordPress.',
                'file' => 'modules/activity/module.php',
                'conflict' => [],
                'snippets' => ['TIBOX — Medición de interacciones (si lo creaste)'],
            ],
            'slider' => [
                'name' => 'Slider de inicio',
                'desc' => 'Shortcode [tbx_hero_lite] con slides editables desde el panel. CSS y JS se cargan solo donde se usa.',
                'file' => 'modules/slider/module.php',
                'conflict' => ['function' => 'tbx_hero_lite_shortcode'],
                'snippets' => [
                    'PHP Slider Ligero página inicio',
                    'Función JS Slider del inicio',
                    'Estilos de Slider Inicio',
                ],
            ],
        ];
    }

    public static function enabled(): array
    {
        $saved = get_option(self::OPTION, []);
        return is_array($saved) ? $saved : [];
    }

    public static function is_enabled(string $key): bool
    {
        return !empty(self::enabled()[$key]);
    }

    public static function is_loaded(string $key): bool
    {
        return in_array($key, self::$loaded, true);
    }

    public static function blocked(): array
    {
        return self::$blocked;
    }

    public static function set_enabled(array $map): void
    {
        $clean = [];
        foreach (array_keys(self::all()) as $key) {
            $clean[$key] = !empty($map[$key]);
        }
        update_option(self::OPTION, $clean, true);
    }

    /**
     * Al activar el plugin todos los módulos quedan apagados:
     * se encienden uno por uno, después de desactivar su snippet.
     */
    public static function on_activate(): void
    {
        if (get_option(self::OPTION, null) === null) {
            add_option(self::OPTION, array_fill_keys(array_keys(self::all()), false), '', true);
        }
        flush_rewrite_rules(false);
    }

    public static function boot(): void
    {
        foreach (self::all() as $key => $module) {
            if (!self::is_enabled($key)) {
                continue;
            }

            $conflict = $module['conflict'];
            if (!empty($conflict['class']) && class_exists($conflict['class'], false)) {
                self::$blocked[$key] = 'El snippet de WPCode sigue activo.';
                continue;
            }
            if (!empty($conflict['function']) && function_exists($conflict['function'])) {
                self::$blocked[$key] = 'El snippet de WPCode sigue activo.';
                continue;
            }

            require_once TBX_ADMIN_DIR . 'includes/' . $module['file'];
            self::$loaded[] = $key;
        }
    }
}
