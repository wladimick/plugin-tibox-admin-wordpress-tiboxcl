<?php
/**
 * Menú lateral «Tibox Admin» con pestañas.
 * Cada módulo registra su pestaña con TBX_Admin::register_tab().
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Admin
{
    /** @var array<string,array{label:string,callback:callable|null,order:int,url:string}> */
    private static array $tabs = [];

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu'], 9);
        add_action('admin_menu', [self::class, 'register_submenus'], 20);
        add_action('admin_enqueue_scripts', [self::class, 'assets']);
        add_action('admin_post_tbx_save_modules', [self::class, 'save_modules']);
        add_action('admin_notices', [self::class, 'blocked_notice']);
        add_action('all_admin_notices', [self::class, 'tabs_on_landing_screens']);

        self::register_tab('resumen', 'Resumen', [self::class, 'render_dashboard'], 0);
        self::register_tab('modulos', 'Módulos', [self::class, 'render_modules'], 90);
    }

    public static function register_tab(string $slug, string $label, ?callable $callback, int $order = 50, string $url = ''): void
    {
        self::$tabs[$slug] = [
            'label' => $label,
            'callback' => $callback,
            'order' => $order,
            'url' => $url,
        ];
    }

    public static function page_slug(string $tab): string
    {
        return $tab === 'resumen' ? TBX_ADMIN_SLUG : TBX_ADMIN_SLUG . '-' . $tab;
    }

    public static function tab_url(string $tab): string
    {
        if (!empty(self::$tabs[$tab]['url'])) {
            return self::$tabs[$tab]['url'];
        }
        return admin_url('admin.php?page=' . self::page_slug($tab));
    }

    private static function sorted_tabs(): array
    {
        $tabs = self::$tabs;
        uasort($tabs, fn($a, $b) => $a['order'] <=> $b['order']);
        return $tabs;
    }

    /* ---------- Menú ---------- */

    public static function register_menu(): void
    {
        add_menu_page(
            'Tibox Admin',
            'Tibox Admin',
            'manage_options',
            TBX_ADMIN_SLUG,
            [self::class, 'render_page'],
            self::menu_icon(),
            3
        );
        /* «Resumen» como primera entrada: evita el «Tibox Admin» duplicado */
        add_submenu_page(TBX_ADMIN_SLUG, 'Tibox Admin', 'Resumen', 'manage_options', TBX_ADMIN_SLUG, [self::class, 'render_page']);
    }

    public static function register_submenus(): void
    {
        foreach (self::sorted_tabs() as $slug => $tab) {
            if ($tab['url'] !== '' || $slug === 'resumen') {
                continue; /* pestañas que apuntan a pantallas propias de WP (ej. Landings) */
            }
            add_submenu_page(
                TBX_ADMIN_SLUG,
                $tab['label'] . ' · Tibox Admin',
                $tab['label'],
                'manage_options',
                self::page_slug($slug),
                [self::class, 'render_page']
            );
        }
    }

    private static function menu_icon(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M10 1.5 2.5 5.6v8.8L10 18.5l7.5-4.1V5.6L10 1.5Zm0 1.7 5.6 3.1L10 9.4 4.4 6.3 10 3.2ZM3.9 7.5l5.4 3v6.1l-5.4-3V7.5Zm6.8 9.1v-6.1l5.4-3v6.1l-5.4 3Z"/></svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    public static function assets(string $hook): void
    {
        $screen = get_current_screen();
        $is_ours = str_contains($hook, TBX_ADMIN_SLUG)
            || ($screen && $screen->post_type === 'tibox_landing');

        if (!$is_ours) {
            return;
        }

        wp_enqueue_style('tbx-admin', TBX_ADMIN_URL . 'assets/admin.css', [], TBX_ADMIN_VERSION);
    }

    /* ---------- Páginas ---------- */

    public static function current_tab(): string
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : TBX_ADMIN_SLUG;
        foreach (array_keys(self::$tabs) as $slug) {
            if (self::page_slug($slug) === $page) {
                return $slug;
            }
        }
        return 'resumen';
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos para ver esta página.', 'tibox-admin'));
        }

        $tab = self::current_tab();

        echo '<div class="wrap tbx-wrap">';
        self::render_header($tab);

        $callback = self::$tabs[$tab]['callback'] ?? null;
        if (is_callable($callback)) {
            call_user_func($callback);
        }

        echo '</div>';
    }

    public static function render_header(string $active): void
    {
        echo '<div class="tbx-head">';
        echo '<div class="tbx-brand"><span class="tbx-logo">TIBOX</span><span class="tbx-sub">Admin</span></div>';
        echo '<div class="tbx-version">v' . esc_html(TBX_ADMIN_VERSION) . '</div>';
        echo '</div>';
        echo '<nav class="tbx-tabs" aria-label="Secciones de Tibox Admin">';
        foreach (self::sorted_tabs() as $slug => $tab) {
            printf(
                '<a href="%1$s" class="tbx-tab%2$s"%3$s>%4$s</a>',
                esc_url(self::tab_url($slug)),
                $slug === $active ? ' is-active' : '',
                $slug === $active ? ' aria-current="page"' : '',
                esc_html($tab['label'])
            );
        }
        echo '</nav>';
        echo '<hr class="wp-header-end">';
    }

    /* Pestañas también sobre las pantallas de Landings (listado y edición) */
    public static function tabs_on_landing_screens(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'tibox_landing' || $screen->base !== 'edit') {
            return;
        }
        echo '<div class="wrap tbx-wrap tbx-wrap--embed">';
        self::render_header('landings');
        echo '</div>';
    }

    /* ---------- Resumen ---------- */

    public static function render_dashboard(): void
    {
        $cards = apply_filters('tbx_admin_dashboard_cards', []);

        echo '<p class="tbx-lead">Panel único para las landings, los leads, la actividad del sitio y el slider de inicio.</p>';

        if ($cards) {
            echo '<div class="tbx-cards">';
            foreach ($cards as $card) {
                printf(
                    '<a class="tbx-card" href="%1$s"><span class="tbx-card__num">%2$s</span><span class="tbx-card__lbl">%3$s</span>%4$s</a>',
                    esc_url($card['url'] ?? '#'),
                    esc_html((string) $card['value']),
                    esc_html($card['label']),
                    !empty($card['note']) ? '<span class="tbx-card__note">' . esc_html($card['note']) . '</span>' : ''
                );
            }
            echo '</div>';
        }

        echo '<h2 class="tbx-h2">Estado de los módulos</h2>';
        self::modules_table(false);
    }

    /* ---------- Módulos ---------- */

    public static function render_modules(): void
    {
        echo '<p class="tbx-lead">Activa cada módulo <strong>después</strong> de desactivar en WPCode el snippet que reemplaza. Si el snippet sigue activo, el módulo no se carga y aparece un aviso.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tbx_save_modules');
        echo '<input type="hidden" name="action" value="tbx_save_modules">';
        self::modules_table(true);
        submit_button('Guardar módulos');
        echo '</form>';
    }

    private static function modules_table(bool $editable): void
    {
        $blocked = TBX_Modules::blocked();

        echo '<table class="widefat tbx-table"><thead><tr>';
        echo $editable ? '<th style="width:70px">Activo</th>' : '';
        echo '<th>Módulo</th><th>Estado</th><th>Reemplaza estos snippets de WPCode</th></tr></thead><tbody>';

        foreach (TBX_Modules::all() as $key => $m) {
            $enabled = TBX_Modules::is_enabled($key);
            if (isset($blocked[$key])) {
                $status = '<span class="tbx-badge tbx-badge--warn">Bloqueado: ' . esc_html($blocked[$key]) . '</span>';
            } elseif (TBX_Modules::is_loaded($key)) {
                $status = '<span class="tbx-badge tbx-badge--ok">Funcionando</span>';
            } else {
                $status = '<span class="tbx-badge">Apagado</span>';
            }

            echo '<tr>';
            if ($editable) {
                printf(
                    '<td><label class="tbx-switch"><input type="checkbox" name="modules[%1$s]" value="1"%2$s><span></span><span class="screen-reader-text">Activar %3$s</span></label></td>',
                    esc_attr($key),
                    checked($enabled, true, false),
                    esc_html($m['name'])
                );
            }
            echo '<td><strong>' . esc_html($m['name']) . '</strong><br><span class="tbx-muted">' . esc_html($m['desc']) . '</span></td>';
            echo '<td>' . $status . '</td>';
            echo '<td><ul class="tbx-list">';
            foreach ($m['snippets'] as $snippet) {
                echo '<li>' . esc_html($snippet) . '</li>';
            }
            echo '</ul></td></tr>';
        }

        echo '</tbody></table>';
    }

    public static function save_modules(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }
        check_admin_referer('tbx_save_modules');

        $map = isset($_POST['modules']) && is_array($_POST['modules']) ? wp_unslash($_POST['modules']) : [];
        TBX_Modules::set_enabled($map);

        /* Las landings registran URLs /lp/: hay que refrescar los enlaces permanentes */
        update_option('tbx_admin_flush_rewrite', 1);

        wp_safe_redirect(add_query_arg('updated', '1', self::tab_url('modulos')));
        exit;
    }

    public static function blocked_notice(): void
    {
        $blocked = TBX_Modules::blocked();
        if (!$blocked || !current_user_can('manage_options')) {
            return;
        }
        $names = array_map(fn($k) => TBX_Modules::all()[$k]['name'], array_keys($blocked));
        printf(
            '<div class="notice notice-warning"><p><strong>Tibox Admin:</strong> %1$s no se cargó porque su snippet de WPCode sigue activo. Desactiva el snippet y recarga. <a href="%2$s">Ver módulos</a></p></div>',
            esc_html(implode(', ', $names)),
            esc_url(self::tab_url('modulos'))
        );
    }
}

/* Refresca enlaces permanentes una vez después de cambiar módulos */
add_action('init', function () {
    if (get_option('tbx_admin_flush_rewrite')) {
        delete_option('tbx_admin_flush_rewrite');
        flush_rewrite_rules(false);
    }
}, 99);
