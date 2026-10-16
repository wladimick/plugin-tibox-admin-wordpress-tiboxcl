<?php
/* Portado desde el snippet de WPCode del mismo nombre. Tibox Admin 1.0.0 */
/**
 * TIBOX — Gestor de Landing Pages v2 (fix: compatibilidad con Claude Design)
 *
 * Modos de renderizado:
 * 1. WordPress / Elementor.
 * 2. Canvas limpio.
 * 3. Documento HTML completo.
 *
 * Incluye control de campañas activas de Google Ads.
 *
 * CAMBIO respecto a la v2 original:
 * El filtro anti-PHP usaba el patrón `/<\?(?:php|=)?[\s\S]*?\?>/i`, que
 * además de bloquear tags PHP reales, también borraba cualquier
 * fragmento que empezara con "<?" y terminara más adelante con "?>"
 * dentro del HTML pegado. Esto incluye declaraciones XML de SVGs
 * embebidos (`<?xml version="1.0"?>`) y coincidencias accidentales
 * dentro del JSON/JS que empaquetan herramientas como Claude Design.
 * Resultado: el documento pegado quedaba truncado/corrupto y el script
 * de "unpacking" del bundle fallaba en el navegador.
 *
 * El fix reemplaza ese patrón genérico por dos patrones específicos que
 * solo detectan tags PHP reales: `<?php ... ?>` y `<?= ... ?>`.
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('TIBOX_Landing_Manager', false)) {

final class TIBOX_Landing_Manager
{
    private const POST_TYPE = 'tibox_landing';
    /*
     * Renderizado.
     */
    private const META_MODE = '_tibox_lp_mode';
    private const META_FULL_HTML = '_tibox_lp_full_html';
    private const META_CUSTOM_CSS = '_tibox_lp_custom_css';
    private const META_CUSTOM_JS = '_tibox_lp_custom_js';
    /*
     * Google Ads.
     */
    private const META_ADS_ACTIVE = '_tibox_lp_ads_active';
    private const META_ADS_CAMPAIGN_NAME = '_tibox_lp_ads_campaign_name';
    private const META_ADS_CAMPAIGN_ID = '_tibox_lp_ads_campaign_id';
    private const META_ADS_START_DATE = '_tibox_lp_ads_start_date';
    private const META_ADS_END_DATE = '_tibox_lp_ads_end_date';
    private const META_ADS_FINAL_URL = '_tibox_lp_ads_final_url';
    private const META_ADS_NOTES = '_tibox_lp_ads_notes';
    public static function init(): void
    {
        add_action('init', [self::class, 'register_post_type']);
        /*
         * Agrega Landings como tipo compatible con Elementor.
         */
        add_action(
            'admin_init',
            [self::class, 'enable_elementor_support']
        );
        add_action(
            'add_meta_boxes',
            [self::class, 'add_meta_boxes']
        );
        add_action(
            'save_post_' . self::POST_TYPE,
            [self::class, 'save_landing']
        );
        /*
         * Solo Canvas y HTML completo interceptan la plantilla.
         * Elementor utiliza el renderizado normal de WordPress.
         */
        add_action(
            'template_redirect',
            [self::class, 'render_landing'],
            0
        );
        add_filter(
            'manage_' . self::POST_TYPE . '_posts_columns',
            [self::class, 'add_admin_columns']
        );
        add_action(
            'manage_' . self::POST_TYPE . '_posts_custom_column',
            [self::class, 'render_admin_columns'],
            10,
            2
        );
        add_filter(
            'manage_edit-' . self::POST_TYPE . '_sortable_columns',
            [self::class, 'sortable_columns']
        );
        add_filter(
            'post_row_actions',
            [self::class, 'add_row_actions'],
            10,
            2
        );
        /*
         * Filtro de campañas en el listado.
         */
        add_action(
            'restrict_manage_posts',
            [self::class, 'render_admin_filters']
        );
        add_action(
            'pre_get_posts',
            [self::class, 'apply_admin_filters']
        );
        /*
         * Protección de landings con campaña activa.
         */
        add_filter(
            'map_meta_cap',
            [self::class, 'protect_active_campaign'],
            10,
            4
        );
        add_action(
            'admin_notices',
            [self::class, 'campaign_admin_notice']
        );
        add_filter(
            'display_post_states',
            [self::class, 'display_post_states'],
            10,
            2
        );
        add_action(
            'admin_enqueue_scripts',
            [self::class, 'admin_assets']
        );
    }
    public static function register_post_type(): void
    {
        $labels = [
            'name' => 'Landings',
            'singular_name' => 'Landing',
            'menu_name' => 'Landings',
            'name_admin_bar' => 'Landing',
            'add_new' => 'Añadir nueva',
            'add_new_item' => 'Crear nueva landing',
            'edit_item' => 'Editar landing',
            'new_item' => 'Nueva landing',
            'view_item' => 'Ver landing',
            'view_items' => 'Ver landings',
            'search_items' => 'Buscar landings',
            'not_found' => 'No se encontraron landings',
            'not_found_in_trash' => 'No hay landings en la papelera',
            'all_items' => 'Todas las landings',
            'archives' => 'Archivo de landings',
            'attributes' => 'Atributos de landing',
            'featured_image' => 'Imagen de referencia',
            'set_featured_image' => 'Asignar imagen',
            'remove_featured_image' => 'Eliminar imagen',
        ];
        register_post_type(self::POST_TYPE, [
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => defined('TBX_ADMIN_SLUG') ? TBX_ADMIN_SLUG : true,
            'show_in_rest' => true,
            'menu_icon' => 'dashicons-welcome-widgets-menus',
            'menu_position' => 21,
            'supports' => [
                'title',
                'editor',
                'thumbnail',
                'revisions',
                'custom-fields',
                'page-attributes',
            ],
            /*
             * Permite que Elementor y otras herramientas
             * lo traten como una página.
             */
            'hierarchical' => false,
            'has_archive' => false,
            'rewrite' => [
                'slug' => 'lp',
                'with_front' => false,
            ],
            'query_var' => true,
            /*
             * Las landings no aparecen en la búsqueda interna.
             */
            'exclude_from_search' => true,
            'capability_type' => 'page',
            'map_meta_cap' => true,
        ]);
    }
    /**
     * Habilita automáticamente el CPT dentro de Elementor.
     *
     * También puede revisarse manualmente en:
     * Elementor → Ajustes → General → Tipos de contenido.
     */
    public static function enable_elementor_support(): void
    {
        if (!did_action('elementor/loaded')) {
            return;
        }
        $supported = get_option(
            'elementor_cpt_support',
            ['page', 'post']
        );
        if (!is_array($supported)) {
            $supported = ['page', 'post'];
        }
        if (!in_array(self::POST_TYPE, $supported, true)) {
            $supported[] = self::POST_TYPE;
            update_option(
                'elementor_cpt_support',
                array_values(array_unique($supported))
            );
        }
    }
    public static function add_meta_boxes(): void
    {
        add_meta_box(
            'tibox-lp-rendering',
            'Tipo de construcción y renderizado',
            [self::class, 'render_settings_metabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'tibox-lp-google-ads',
            'Campaña de Google Ads',
            [self::class, 'render_google_ads_metabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );
        add_meta_box(
            'tibox-lp-full-html',
            'Documento HTML completo',
            [self::class, 'render_full_html_metabox'],
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'tibox-lp-assets',
            'CSS y JavaScript adicionales',
            [self::class, 'render_assets_metabox'],
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'tibox-lp-help',
            'Instrucciones',
            [self::class, 'render_help_metabox'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }
    public static function render_settings_metabox(
        WP_Post $post
    ): void {
        wp_nonce_field(
            'tibox_lp_save',
            'tibox_lp_nonce'
        );
        $mode = get_post_meta(
            $post->ID,
            self::META_MODE,
            true
        );
        if (
            !in_array(
                $mode,
                ['wordpress', 'canvas', 'full_html'],
                true
            )
        ) {
            /*
             * WordPress / Elementor es ahora el modo predeterminado.
             */
            $mode = 'wordpress';
        }
        ?>
        <div class="tibox-lp-mode-selector">
            <label class="tibox-lp-mode-card">
                <input
                    type="radio"
                    name="tibox_lp_mode"
                    value="wordpress"
                    <?php checked($mode, 'wordpress'); ?>
                >
                <span class="dashicons dashicons-admin-page"></span>
                <strong>WordPress / Elementor</strong>
                <small>
                    Utiliza la plantilla normal de WordPress.
                    Permite editar la página con Elementor o copiar
                    contenido desde otra página.
                </small>
            </label>
            <label class="tibox-lp-mode-card">
                <input
                    type="radio"
                    name="tibox_lp_mode"
                    value="canvas"
                    <?php checked($mode, 'canvas'); ?>
                >
                <span class="dashicons dashicons-editor-code"></span>
                <strong>Canvas limpio</strong>
                <small>
                    WordPress genera el documento, pero no carga
                    header, footer ni contenedores del tema.
                </small>
            </label>
            <label class="tibox-lp-mode-card">
                <input
                    type="radio"
                    name="tibox_lp_mode"
                    value="full_html"
                    <?php checked($mode, 'full_html'); ?>
                >
                <span class="dashicons dashicons-media-code"></span>
                <strong>Documento HTML completo</strong>
                <small>
                    Permite pegar un documento completo generado
                    con Claude, ChatGPT u otra herramienta.
                </small>
            </label>
        </div>
        <hr>
        <p>
            <strong>URL prevista:</strong><br>
            <code>
                <?php
                echo esc_html(
                    home_url(
                        '/lp/' .
                        ($post->post_name ?: 'nombre-landing') .
                        '/'
                    )
                );
                ?>
            </code>
        </p>
        <?php if ($mode === 'wordpress') : ?>
            <p class="description">
                En este modo puedes usar el editor normal o presionar
                “Editar con Elementor”. El header y footer dependerán
                de la plantilla seleccionada en Elementor o en el tema.
            </p>
        <?php endif; ?>
        <?php
    }
    public static function render_google_ads_metabox(
        WP_Post $post
    ): void {
        $active = get_post_meta(
            $post->ID,
            self::META_ADS_ACTIVE,
            true
        );
        $campaign_name = get_post_meta(
            $post->ID,
            self::META_ADS_CAMPAIGN_NAME,
            true
        );
        $campaign_id = get_post_meta(
            $post->ID,
            self::META_ADS_CAMPAIGN_ID,
            true
        );
        $start_date = get_post_meta(
            $post->ID,
            self::META_ADS_START_DATE,
            true
        );
        $end_date = get_post_meta(
            $post->ID,
            self::META_ADS_END_DATE,
            true
        );
        $final_url = get_post_meta(
            $post->ID,
            self::META_ADS_FINAL_URL,
            true
        );
        $notes = get_post_meta(
            $post->ID,
            self::META_ADS_NOTES,
            true
        );
        ?>
        <div class="tibox-ads-status-box">
            <label>
                <input
                    type="checkbox"
                    name="tibox_lp_ads_active"
                    value="1"
                    <?php checked($active, '1'); ?>
                >
                <strong>
                    ¿Esta landing tiene una campaña activa
                    de Google Ads?
                </strong>
            </label>
            <p class="description">
                Mientras esta opción esté activa, la landing quedará
                protegida contra modificaciones realizadas por usuarios
                que no sean administradores.
            </p>
        </div>
        <div class="tibox-lp-fields-grid">
            <p>
                <label for="tibox-lp-campaign-name">
                    <strong>Nombre de campaña</strong>
                </label>
                <input
                    id="tibox-lp-campaign-name"
                    name="tibox_lp_ads_campaign_name"
                    type="text"
                    class="widefat"
                    value="<?php echo esc_attr($campaign_name); ?>"
                    placeholder="Ej.: Soporte TI Empresas — Search"
                >
            </p>
            <p>
                <label for="tibox-lp-campaign-id">
                    <strong>ID de campaña de Google Ads</strong>
                </label>
                <input
                    id="tibox-lp-campaign-id"
                    name="tibox_lp_ads_campaign_id"
                    type="text"
                    class="widefat"
                    value="<?php echo esc_attr($campaign_id); ?>"
                    placeholder="Ej.: 12345678901"
                >
            </p>
            <p>
                <label for="tibox-lp-start-date">
                    <strong>Fecha de inicio</strong>
                </label>
                <input
                    id="tibox-lp-start-date"
                    name="tibox_lp_ads_start_date"
                    type="date"
                    class="widefat"
                    value="<?php echo esc_attr($start_date); ?>"
                >
            </p>
            <p>
                <label for="tibox-lp-end-date">
                    <strong>Fecha de término</strong>
                </label>
                <input
                    id="tibox-lp-end-date"
                    name="tibox_lp_ads_end_date"
                    type="date"
                    class="widefat"
                    value="<?php echo esc_attr($end_date); ?>"
                >
            </p>
            <p class="tibox-full-width">
                <label for="tibox-lp-final-url">
                    <strong>URL final configurada en Google Ads</strong>
                </label>
                <input
                    id="tibox-lp-final-url"
                    name="tibox_lp_ads_final_url"
                    type="url"
                    class="widefat"
                    value="<?php echo esc_attr($final_url); ?>"
                    placeholder="<?php echo esc_attr(
                        get_permalink($post)
                    ); ?>"
                >
                <small>
                    Permite comprobar que Google Ads utiliza la misma URL
                    publicada en WordPress.
                </small>
            </p>
            <p class="tibox-full-width">
                <label for="tibox-lp-ads-notes">
                    <strong>Notas internas</strong>
                </label>
                <textarea
                    id="tibox-lp-ads-notes"
                    name="tibox_lp_ads_notes"
                    rows="4"
                    class="widefat"
                    placeholder="Responsable, campaña relacionada, restricciones o cambios pendientes."
                ><?php echo esc_textarea($notes); ?></textarea>
            </p>
        </div>
        <?php
    }
    public static function render_full_html_metabox(
        WP_Post $post
    ): void {
        $html = get_post_meta(
            $post->ID,
            self::META_FULL_HTML,
            true
        );
        ?>
        <p>
            Este campo se utiliza únicamente en el modo
            <strong>Documento HTML completo</strong>.
        </p>
        <textarea
            name="tibox_lp_full_html"
            rows="28"
            class="widefat code"
            spellcheck="false"
            placeholder="<!DOCTYPE html>
<html lang='es'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1'>
    <title>Landing TIBOX</title>
</head>
<body>
    ...
</body>
</html>"
        ><?php echo esc_textarea($html); ?></textarea>
        <p>
            <strong>
                No pegues PHP ni credenciales de SendGrid.
            </strong>
        </p>
        <?php
    }
    public static function render_assets_metabox(
        WP_Post $post
    ): void {
        $css = get_post_meta(
            $post->ID,
            self::META_CUSTOM_CSS,
            true
        );
        $js = get_post_meta(
            $post->ID,
            self::META_CUSTOM_JS,
            true
        );
        ?>
        <p>
            Estos campos se aplican al modo
            <strong>Canvas limpio</strong>.
        </p>
        <p>
            <label for="tibox-lp-css">
                <strong>CSS adicional</strong>
            </label>
        </p>
        <textarea
            id="tibox-lp-css"
            name="tibox_lp_custom_css"
            rows="14"
            class="widefat code"
            spellcheck="false"
        ><?php echo esc_textarea($css); ?></textarea>
        <p>
            <label for="tibox-lp-js">
                <strong>JavaScript adicional</strong>
            </label>
        </p>
        <textarea
            id="tibox-lp-js"
            name="tibox_lp_custom_js"
            rows="14"
            class="widefat code"
            spellcheck="false"
        ><?php echo esc_textarea($js); ?></textarea>
        <?php
    }
    public static function render_help_metabox(
        WP_Post $post
    ): void {
        ?>
        <p><strong>WordPress / Elementor</strong></p>
        <p>
            Utiliza el editor normal o Elementor. Es la opción
            recomendada para copiar una página existente y adaptarla.
        </p>
        <p><strong>Canvas limpio</strong></p>
        <p>
            Utiliza el editor principal, sin header ni footer del tema.
        </p>
        <p><strong>HTML completo</strong></p>
        <p>
            Pega un documento desde
            <code>&lt;!DOCTYPE html&gt;</code>
            hasta
            <code>&lt;/html&gt;</code>.
        </p>
        <p><strong>Formularios</strong></p>
        <code style="word-break:break-all;">
            /wp-json/tibox/v1/lead
        </code>
        <?php if (self::has_active_campaign($post->ID)) : ?>
            <hr>
            <p style="color:#b32d2e;">
                <strong>Campaña activa</strong>
            </p>
            <p>
                Evita cambios directos. Duplica la landing para realizar
                una nueva versión y probarla antes de reemplazarla.
            </p>
        <?php endif; ?>
        <?php
    }
    public static function save_landing(int $post_id): void
    {
        if (
            !isset($_POST['tibox_lp_nonce']) ||
            !wp_verify_nonce(
                sanitize_text_field(
                    wp_unslash($_POST['tibox_lp_nonce'])
                ),
                'tibox_lp_save'
            )
        ) {
            return;
        }
        if (
            defined('DOING_AUTOSAVE') &&
            DOING_AUTOSAVE
        ) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        /*
         * Solo administradores pueden guardar configuraciones
         * que admiten HTML o JavaScript sin filtrar.
         */
        if (!current_user_can('manage_options')) {
            return;
        }
        $allowed_modes = [
            'wordpress',
            'canvas',
            'full_html',
        ];
        $mode = isset($_POST['tibox_lp_mode'])
            ? sanitize_key(
                wp_unslash($_POST['tibox_lp_mode'])
            )
            : 'wordpress';
        if (!in_array($mode, $allowed_modes, true)) {
            $mode = 'wordpress';
        }
        update_post_meta(
            $post_id,
            self::META_MODE,
            $mode
        );
        $full_html = isset($_POST['tibox_lp_full_html'])
            ? wp_unslash($_POST['tibox_lp_full_html'])
            : '';
        $custom_css = isset($_POST['tibox_lp_custom_css'])
            ? wp_unslash($_POST['tibox_lp_custom_css'])
            : '';
        $custom_js = isset($_POST['tibox_lp_custom_js'])
            ? wp_unslash($_POST['tibox_lp_custom_js'])
            : '';
        /*
         * PHP nunca se admite dentro del código pegado.
         *
         * FIX: el patrón original `/<\?(?:php|=)?[\s\S]*?\?>/i` también
         * borraba cualquier "<? ... ?>" que no fuera PHP real (por
         * ejemplo, declaraciones XML de SVGs embebidos como
         * `<?xml version="1.0"?>`, o coincidencias accidentales dentro
         * de JSON/JS empaquetado por herramientas como Claude Design).
         * Ahora se usan dos patrones específicos que solo detectan
         * tags PHP reales: `<?php ... ?>` y `<?= ... ?>`.
         */
        $full_html = self::strip_php_tags($full_html);
        $custom_css = self::strip_php_tags($custom_css);
        $custom_js = self::strip_php_tags($custom_js);
        update_post_meta(
            $post_id,
            self::META_FULL_HTML,
            $full_html
        );
        update_post_meta(
            $post_id,
            self::META_CUSTOM_CSS,
            $custom_css
        );
        update_post_meta(
            $post_id,
            self::META_CUSTOM_JS,
            $custom_js
        );
        /*
         * Google Ads.
         */
        $ads_active = isset($_POST['tibox_lp_ads_active'])
            ? '1'
            : '0';
        update_post_meta(
            $post_id,
            self::META_ADS_ACTIVE,
            $ads_active
        );
        self::save_text_meta(
            $post_id,
            self::META_ADS_CAMPAIGN_NAME,
            'tibox_lp_ads_campaign_name'
        );
        self::save_text_meta(
            $post_id,
            self::META_ADS_CAMPAIGN_ID,
            'tibox_lp_ads_campaign_id'
        );
        self::save_date_meta(
            $post_id,
            self::META_ADS_START_DATE,
            'tibox_lp_ads_start_date'
        );
        self::save_date_meta(
            $post_id,
            self::META_ADS_END_DATE,
            'tibox_lp_ads_end_date'
        );
        $final_url = isset($_POST['tibox_lp_ads_final_url'])
            ? esc_url_raw(
                wp_unslash($_POST['tibox_lp_ads_final_url'])
            )
            : '';
        update_post_meta(
            $post_id,
            self::META_ADS_FINAL_URL,
            $final_url
        );
        $notes = isset($_POST['tibox_lp_ads_notes'])
            ? sanitize_textarea_field(
                wp_unslash($_POST['tibox_lp_ads_notes'])
            )
            : '';
        update_post_meta(
            $post_id,
            self::META_ADS_NOTES,
            $notes
        );
    }
    /**
     * Elimina únicamente tags PHP reales (`<?php ... ?>` y `<?= ... ?>`).
     *
     * A diferencia del patrón anterior, no toca declaraciones XML
     * (`<?xml ... ?>`) ni fragmentos de JSON/JS que casualmente
     * contengan "<?" y "?>" en cualquier orden.
     */
    private static function strip_php_tags(string $content): string
    {
        $content = preg_replace(
            '/<\?php\b[\s\S]*?\?>/i',
            '',
            $content
        );
        $content = preg_replace(
            '/<\?=[\s\S]*?\?>/i',
            '',
            $content
        );
        return $content;
    }
    private static function save_text_meta(
        int $post_id,
        string $meta_key,
        string $input_name
    ): void {
        $value = isset($_POST[$input_name])
            ? sanitize_text_field(
                wp_unslash($_POST[$input_name])
            )
            : '';
        update_post_meta(
            $post_id,
            $meta_key,
            $value
        );
    }
    private static function save_date_meta(
        int $post_id,
        string $meta_key,
        string $input_name
    ): void {
        $value = isset($_POST[$input_name])
            ? sanitize_text_field(
                wp_unslash($_POST[$input_name])
            )
            : '';
        /*
         * Solo admite YYYY-MM-DD.
         */
        if (
            $value !== '' &&
            !preg_match(
                '/^\d{4}-\d{2}-\d{2}$/',
                $value
            )
        ) {
            $value = '';
        }
        update_post_meta(
            $post_id,
            $meta_key,
            $value
        );
    }
    public static function render_landing(): void
    {
        if (
            is_admin() ||
            !is_singular(self::POST_TYPE) ||
            is_feed() ||
            is_embed()
        ) {
            return;
        }
        $post_id = get_queried_object_id();
        $post = get_post($post_id);
        if (!$post instanceof WP_Post) {
            return;
        }
        $mode = get_post_meta(
            $post_id,
            self::META_MODE,
            true
        );
        /*
         * WordPress / Elementor:
         * no interceptar. El tema y Elementor renderizan normalmente.
         */
        if (
            $mode === '' ||
            $mode === 'wordpress'
        ) {
            return;
        }
        if ($mode === 'full_html') {
            self::render_full_document($post);
        }
        if ($mode === 'canvas') {
            self::render_canvas($post);
        }
    }
    /**
     * Las landings en modo HTML completo omiten wp_head/wp_footer.
     * Inyectamos el puente independiente solo si el usuario habilitó
     * la compatibilidad de conversiones, sin modificar el HTML almacenado.
     */
    public static function inject_native_gtm_bridge(string $html): string
    {
        if (!class_exists('TBX_Native_Contacts', false)) {
            return $html;
        }
        $script = TBX_Native_Contacts::full_html_form_gtm_snippet();
        if ($script === '' || strpos($html, 'id="tbx-landing-lead-bridge"') !== false) {
            return $html;
        }
        if (preg_match('/<head\\b[^>]*>/i', $html)) {
            return preg_replace_callback('/<head\\b[^>]*>/i',
                static fn (array $match): string => $match[0] . "\n" . $script, $html, 1) ?? $html;
        }
        if (stripos($html, '</body>') !== false) {
            return preg_replace_callback('/<\\/body\\s*>/i',
                static fn (array $match): string => $script . "\n" . $match[0], $html, 1) ?? $html;
        }
        return $html . "\n" . $script;
    }

    private static function render_full_document(
        WP_Post $post
    ): void {
        $html = (string) get_post_meta(
            $post->ID,
            self::META_FULL_HTML,
            true
        );
        if (trim($html) === '') {
            status_header(500);
            echo 'La landing no tiene un documento HTML configurado.';
            exit;
        }
        status_header(200);
        header(
            'Content-Type: text/html; charset=' .
            get_bloginfo('charset')
        );
        header('X-Content-Type-Options: nosniff');
        header(
            'Referrer-Policy: strict-origin-when-cross-origin'
        );
        $replacements = [
            '{{SITE_URL}}' => esc_url(home_url('/')),
            '{{LANDING_URL}}' => esc_url(
                get_permalink($post)
            ),
            '{{FORM_ENDPOINT}}' => esc_url(
                rest_url('tibox/v1/lead')
            ),
            '{{PRIVACY_URL}}' => esc_url(
                home_url('/aviso-de-privacidad/')
            ),
            '{{CURRENT_YEAR}}' => esc_html(
                wp_date('Y')
            ),
        ];
        $html = strtr(
            $html,
            $replacements
        );
        $html = self::inject_native_gtm_bridge($html);
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
    private static function render_canvas(
        WP_Post $post
    ): void {
        $custom_css = (string) get_post_meta(
            $post->ID,
            self::META_CUSTOM_CSS,
            true
        );
        $custom_js = (string) get_post_meta(
            $post->ID,
            self::META_CUSTOM_JS,
            true
        );
        status_header(200);
        header(
            'Content-Type: text/html; charset=' .
            get_bloginfo('charset')
        );
        header('X-Content-Type-Options: nosniff');
        header(
            'Referrer-Policy: strict-origin-when-cross-origin'
        );
        ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1, viewport-fit=cover"
    >
    <title><?php echo esc_html(
        get_the_title($post)
    ); ?></title>
    <?php wp_head(); ?>
    <?php if (trim($custom_css) !== '') : ?>
        <style id="tibox-lp-custom-css">
            <?php
            echo $custom_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </style>
    <?php endif; ?>
</head>
<body
    <?php
    body_class([
        'tibox-landing',
        'tibox-landing-canvas',
        'tibox-landing-' . $post->post_name,
    ]);
    ?>
>
<?php wp_body_open(); ?>
<main id="tibox-landing-content">
    <?php
    echo apply_filters(
        'the_content',
        $post->post_content
    ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    ?>
</main>
<?php wp_footer(); ?>
<?php if (trim($custom_js) !== '') : ?>
    <script id="tibox-lp-custom-js">
        <?php
        echo $custom_js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
    </script>
<?php endif; ?>
</body>
</html>
        <?php
        exit;
    }
    public static function add_admin_columns(
        array $columns
    ): array {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['tibox_lp_mode'] = 'Construcción';
                $new_columns['tibox_lp_ads'] = 'Google Ads';
                $new_columns['tibox_lp_campaign'] = 'Campaña';
                $new_columns['tibox_lp_url'] = 'URL';
            }
        }
        return $new_columns;
    }
    public static function render_admin_columns(
        string $column,
        int $post_id
    ): void {
        if ($column === 'tibox_lp_mode') {
            $mode = get_post_meta(
                $post_id,
                self::META_MODE,
                true
            );
            $labels = [
                'wordpress' => 'WordPress / Elementor',
                'canvas' => 'Canvas',
                'full_html' => 'HTML completo',
            ];
            if (!isset($labels[$mode])) {
                $mode = 'wordpress';
            }
            echo '<strong>' .
                esc_html($labels[$mode]) .
                '</strong>';
        }
        if ($column === 'tibox_lp_ads') {
            if (self::has_active_campaign($post_id)) {
                echo self::campaign_badge(
                    'Campaña activa',
                    'active'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            } else {
                echo self::campaign_badge(
                    'Sin campaña',
                    'inactive'
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            }
        }
        if ($column === 'tibox_lp_campaign') {
            $name = get_post_meta(
                $post_id,
                self::META_ADS_CAMPAIGN_NAME,
                true
            );
            $campaign_id = get_post_meta(
                $post_id,
                self::META_ADS_CAMPAIGN_ID,
                true
            );
            if ($name !== '') {
                echo '<strong>' .
                    esc_html($name) .
                    '</strong>';
            } else {
                echo '<span style="color:#777;">—</span>';
            }
            if ($campaign_id !== '') {
                echo '<br><small>ID: ' .
                    esc_html($campaign_id) .
                    '</small>';
            }
        }
        if ($column === 'tibox_lp_url') {
            $url = get_permalink($post_id);
            echo '<a href="' .
                esc_url($url) .
                '" target="_blank" rel="noopener">';
            echo esc_html(
                wp_make_link_relative($url)
            );
            echo '</a>';
        }
    }
    private static function campaign_badge(
        string $label,
        string $type
    ): string {
        $style = $type === 'active'
            ? 'background:#dcfce7;color:#166534;border-color:#86efac;'
            : 'background:#f3f4f6;color:#4b5563;border-color:#d1d5db;';
        return sprintf(
            '<span style="
                display:inline-block;
                padding:4px 9px;
                border:1px solid;
                border-radius:999px;
                font-size:12px;
                font-weight:700;
                %s
            ">%s</span>',
            esc_attr($style),
            esc_html($label)
        );
    }
    public static function sortable_columns(
        array $columns
    ): array {
        $columns['tibox_lp_ads'] = 'tibox_lp_ads';
        return $columns;
    }
    public static function add_row_actions(
        array $actions,
        WP_Post $post
    ): array {
        if ($post->post_type !== self::POST_TYPE) {
            return $actions;
        }
        $actions['tibox_open'] = sprintf(
            '<a href="%s" target="_blank" rel="noopener">
                Abrir landing
            </a>',
            esc_url(get_permalink($post))
        );
        /*
         * Si la campaña está activa, ocultar edición y papelera
         * para usuarios no administradores.
         */
        if (
            self::has_active_campaign($post->ID) &&
            !current_user_can('manage_options')
        ) {
            unset($actions['edit']);
            unset($actions['inline hide-if-no-js']);
            unset($actions['trash']);
            $actions['tibox_locked'] =
                '<span style="color:#b32d2e;font-weight:600;">
                    Protegida por campaña activa
                </span>';
        }
        return $actions;
    }
    public static function render_admin_filters(
        string $post_type
    ): void {
        if ($post_type !== self::POST_TYPE) {
            return;
        }
        $selected = isset($_GET['tibox_ads_status'])
            ? sanitize_key(
                wp_unslash($_GET['tibox_ads_status'])
            )
            : '';
        ?>
        <select name="tibox_ads_status">
            <option value="">
                Todas las campañas
            </option>
            <option
                value="active"
                <?php selected($selected, 'active'); ?>
            >
                Campaña activa
            </option>
            <option
                value="inactive"
                <?php selected($selected, 'inactive'); ?>
            >
                Sin campaña activa
            </option>
        </select>
        <?php
    }
    public static function apply_admin_filters(
        WP_Query $query
    ): void {
        if (
            !is_admin() ||
            !$query->is_main_query() ||
            $query->get('post_type') !== self::POST_TYPE
        ) {
            return;
        }
        $selected = isset($_GET['tibox_ads_status'])
            ? sanitize_key(
                wp_unslash($_GET['tibox_ads_status'])
            )
            : '';
        if ($selected === 'active') {
            $query->set('meta_query', [
                [
                    'key' => self::META_ADS_ACTIVE,
                    'value' => '1',
                ],
            ]);
        }
        if ($selected === 'inactive') {
            $query->set('meta_query', [
                'relation' => 'OR',
                [
                    'key' => self::META_ADS_ACTIVE,
                    'value' => '0',
                ],
                [
                    'key' => self::META_ADS_ACTIVE,
                    'compare' => 'NOT EXISTS',
                ],
            ]);
        }
        if ($query->get('orderby') === 'tibox_lp_ads') {
            $query->set(
                'meta_key',
                self::META_ADS_ACTIVE
            );
            $query->set(
                'orderby',
                'meta_value'
            );
        }
    }
    /**
     * Impide que usuarios no administradores editen o eliminen
     * una landing con campaña activa.
     */
    public static function protect_active_campaign(
        array $caps,
        string $cap,
        int $user_id,
        array $args
    ): array {
        if (
            !in_array(
                $cap,
                ['edit_post', 'delete_post'],
                true
            ) ||
            empty($args[0])
        ) {
            return $caps;
        }
        $post_id = absint($args[0]);
        if (
            get_post_type($post_id) !== self::POST_TYPE ||
            !self::has_active_campaign($post_id)
        ) {
            return $caps;
        }
        /*
         * Los administradores conservan acceso.
         */
        $user = get_userdata($user_id);
        if (
            $user instanceof WP_User &&
            user_can($user, 'manage_options')
        ) {
            return $caps;
        }
        return ['do_not_allow'];
    }
    public static function campaign_admin_notice(): void
    {
        $screen = get_current_screen();
        if (
            !$screen ||
            $screen->post_type !== self::POST_TYPE
        ) {
            return;
        }
        $post_id = isset($_GET['post'])
            ? absint($_GET['post'])
            : 0;
        if (
            $post_id <= 0 ||
            !self::has_active_campaign($post_id)
        ) {
            return;
        }
        $campaign_name = get_post_meta(
            $post_id,
            self::META_ADS_CAMPAIGN_NAME,
            true
        );
        echo '<div class="notice notice-warning">';
        echo '<p><strong>';
        echo 'Esta landing tiene una campaña activa de Google Ads.';
        echo '</strong></p>';
        echo '<p>';
        if ($campaign_name !== '') {
            echo 'Campaña: <strong>' .
                esc_html($campaign_name) .
                '</strong>. ';
        }
        echo 'Cualquier cambio puede afectar la conversión, la medición ' .
            'o la aprobación de los anuncios. Realiza una copia para ' .
            'trabajar en una nueva versión.';
        echo '</p>';
        echo '</div>';
    }
    public static function display_post_states(
        array $states,
        WP_Post $post
    ): array {
        if (
            $post->post_type === self::POST_TYPE &&
            self::has_active_campaign($post->ID)
        ) {
            $states['tibox_ads_active'] =
                'Google Ads activa';
        }
        return $states;
    }
    private static function has_active_campaign(
        int $post_id
    ): bool {
        return get_post_meta(
            $post_id,
            self::META_ADS_ACTIVE,
            true
        ) === '1';
    }
    public static function admin_assets(
        string $hook
    ): void {
        $screen = get_current_screen();
        if (
            !$screen ||
            $screen->post_type !== self::POST_TYPE
        ) {
            return;
        }
        $css = '
            .tibox-lp-mode-selector {
                display:grid;
                grid-template-columns:repeat(3, minmax(0, 1fr));
                gap:14px;
                margin:16px 0;
            }
            .tibox-lp-mode-card {
                display:flex;
                flex-direction:column;
                gap:8px;
                min-height:140px;
                padding:18px;
                border:1px solid #dcdcde;
                border-radius:8px;
                background:#fff;
                cursor:pointer;
            }
            .tibox-lp-mode-card:has(input:checked) {
                border-color:#2271b1;
                box-shadow:0 0 0 1px #2271b1;
                background:#f0f6fc;
            }
            .tibox-lp-mode-card input {
                align-self:flex-start;
            }
            .tibox-lp-mode-card .dashicons {
                width:28px;
                height:28px;
                font-size:28px;
                color:#2271b1;
            }
            .tibox-lp-mode-card strong {
                font-size:15px;
            }
            .tibox-lp-mode-card small {
                color:#50575e;
                line-height:1.45;
            }
            .tibox-ads-status-box {
                padding:16px;
                margin-bottom:18px;
                border:1px solid #f0b849;
                border-radius:7px;
                background:#fcf9e8;
            }
            .tibox-lp-fields-grid {
                display:grid;
                grid-template-columns:repeat(2, minmax(0, 1fr));
                gap:4px 18px;
            }
            .tibox-full-width {
                grid-column:1 / -1;
            }
            @media (max-width: 900px) {
                .tibox-lp-mode-selector,
                .tibox-lp-fields-grid {
                    grid-template-columns:1fr;
                }
                .tibox-full-width {
                    grid-column:auto;
                }
            }
        ';
        wp_add_inline_style(
            'wp-admin',
            $css
        );
    }
}
TIBOX_Landing_Manager::init();

}
