<?php
/**
 * Módulo Slider de inicio: shortcode [tbx_hero_lite].
 * Mismo HTML, CSS y JS que el snippet anterior, con los slides
 * editables desde Tibox Admin › Slider. CSS y JS se cargan solo
 * en la portada o donde se use el shortcode.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class TBX_Slider
{
    public const OPTION = 'tbx_slider_slides';
    public const MAX_SLIDES = 8;

    public static function init(): void
    {
        add_shortcode('tbx_hero_lite', [self::class, 'shortcode']);
        add_action('wp_enqueue_scripts', [self::class, 'register_assets'], 5);
        add_action('wp_enqueue_scripts', [self::class, 'maybe_enqueue'], 20);
        add_action('admin_post_tbx_save_slider', [self::class, 'save']);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);

        TBX_Admin::register_tab('slider', 'Slider', [self::class, 'render_admin'], 40);
    }

    /* Slides del snippet original: quedan como valor inicial */
    public static function defaults(): array
    {
        $base = 'https://www.tibox.cl/wp-content/uploads/2026/03/';
        $rows = [
            ['Impulsa tu negocio <br> con tecnología', 'Fortalecemos, optimizamos y digitalizamos tu empresa con soluciones TI a la medida de tu negocio', 'slide1'],
            ['Diseñamos tu <br> entorno digital', 'Conectamos tu negocio con su mejor versión. Integramos tecnología, personas y procesos', 'slide2'],
            ['Un solo partner, <br> todas tus soluciones TI', 'Innovamos contigo para lograr resultados reales con soluciones tecnológicas de alto impacto', 'slide3'],
            ['Tecnología con visión', 'Entendemos tu negocio y construimos contigo soluciones de alto impacto', 'slide4'],
            ['Tu transformación digital <br> comienza hoy', 'Dale el siguiente paso a tu negocio. Estamos listos para acompañarte en tu evolución digital', 'slide5'],
        ];
        $slides = [];
        foreach ($rows as [$title, $text, $img]) {
            $slides[] = [
                'title' => $title,
                'text' => $text,
                'image' => $base . $img . '.webp',
                'mobile_image' => $base . $img . '-mobile.webp',
                'url' => '',
                'label' => '',
            ];
        }
        return $slides;
    }

    public static function slides(): array
    {
        $saved = get_option(self::OPTION, null);
        if (!is_array($saved) || $saved === []) {
            return self::defaults();
        }
        return array_values(array_filter($saved, fn($s) => !empty($s['image']) || !empty($s['title'])));
    }

    /* ---------- Assets ---------- */

    public static function register_assets(): void
    {
        $css = TBX_ADMIN_DIR . 'assets/slider.css';
        $js = TBX_ADMIN_DIR . 'assets/slider.js';
        wp_register_style('tbx-slider', TBX_ADMIN_URL . 'assets/slider.css', [], (string) filemtime($css));
        wp_register_script('tbx-slider', TBX_ADMIN_URL . 'assets/slider.js', [], (string) filemtime($js), ['in_footer' => true, 'strategy' => 'defer']);
    }

    /* En la portada (o páginas con el shortcode) el CSS va en el <head> para evitar saltos */
    public static function maybe_enqueue(): void
    {
        $post = get_post();
        $uses = is_front_page()
            || ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'tbx_hero_lite'));
        if ($uses) {
            wp_enqueue_style('tbx-slider');
            wp_enqueue_script('tbx-slider');
        }
    }

    /* ---------- Shortcode (HTML idéntico al snippet) ---------- */

    private static function filter_mobile_ids(string $ids_string, int $total): array
    {
        $ids = array_filter(array_map('intval', explode(',', $ids_string)));
        $ids = array_values(array_unique(array_filter($ids, fn($n) => $n >= 1 && $n <= $total)));
        $ids = array_slice($ids, 0, 2);
        return $ids ?: [1];
    }

    public static function shortcode($atts = []): string
    {
        /* Por si el shortcode está en un lugar que maybe_enqueue no detecta (ej. Elementor) */
        wp_enqueue_style('tbx-slider');
        wp_enqueue_script('tbx-slider');

        $atts = shortcode_atts([
            'autoplay' => '5000',
            'height' => '88vh',
            'mobile_ids' => '1',
            'mobile_breakpoint' => '767',
        ], $atts, 'tbx_hero_lite');

        $slides = self::slides();
        if (!$slides) {
            return '';
        }

        $mobile_breakpoint = (int) $atts['mobile_breakpoint'];
        $mobile_ids = self::filter_mobile_ids((string) $atts['mobile_ids'], count($slides));
        $first_mobile_id = (int) $mobile_ids[0];
        $slider_id = 'tbx-hero-lite-' . wp_rand(1000, 999999);

        $first_desktop_image = $slides[0]['image'] ?? '';
        $first_mobile_slide = $slides[$first_mobile_id - 1] ?? $slides[0];
        $first_mobile_image = !empty($first_mobile_slide['mobile_image']) ? $first_mobile_slide['mobile_image'] : ($first_mobile_slide['image'] ?? '');

        ob_start();
        if ($first_desktop_image) {
            printf('<link rel="preload" as="image" href="%s" media="(min-width: %dpx)">', esc_url($first_desktop_image), $mobile_breakpoint + 1);
        }
        if ($first_mobile_image) {
            printf('<link rel="preload" as="image" href="%s" media="(max-width: %dpx)">', esc_url($first_mobile_image), $mobile_breakpoint);
        }
        ?>
        <section
            id="<?php echo esc_attr($slider_id); ?>"
            class="tbx-hero-lite"
            data-autoplay="<?php echo esc_attr((string) (int) $atts['autoplay']); ?>"
            data-mobile-ids="<?php echo esc_attr(implode(',', $mobile_ids)); ?>"
            data-breakpoint="<?php echo esc_attr((string) $mobile_breakpoint); ?>"
            style="--tbx-hero-height: <?php echo esc_attr($atts['height']); ?>;"
            aria-label="Slider principal"
        >
            <?php foreach ($slides as $index => $slide) :
                $number = $index + 1;
                $desktop_image = $slide['image'] ?? '';
                $mobile_image = !empty($slide['mobile_image']) ? $slide['mobile_image'] : $desktop_image;
                $classes = ['tbx-hero-lite__slide'];
                $inline_style = '';
                if ($index === 0) {
                    $classes[] = 'is-initial-desktop';
                    if ($desktop_image) {
                        $inline_style = 'background-image:url(' . esc_url($desktop_image) . ');';
                    }
                }
                if ($number === $first_mobile_id && $first_mobile_id !== 1) {
                    $classes[] = 'is-initial-mobile';
                    if ($mobile_image) {
                        $inline_style = 'background-image:url(' . esc_url($mobile_image) . ');';
                    }
                }
                ?>
                <article
                    class="<?php echo esc_attr(implode(' ', $classes)); ?>"
                    data-index="<?php echo esc_attr((string) $number); ?>"
                    data-bg="<?php echo esc_url($desktop_image); ?>"
                    data-bg-mobile="<?php echo esc_url($mobile_image); ?>"
                    <?php if ($inline_style) : ?>style="<?php echo esc_attr($inline_style); ?>"<?php endif; ?>
                    <?php if ($index === 0 || $number === $first_mobile_id) : ?>data-priority="high"<?php endif; ?>
                >
                    <div class="tbx-hero-lite__overlay"></div>
                    <div class="tbx-hero-lite__inner">
                        <div class="tbx-hero-lite__content">
                            <h2 class="tbx-hero-lite__title"><?php echo wp_kses($slide['title'] ?? '', ['br' => []]); ?></h2>
                            <?php if (!empty($slide['text'])) : ?>
                                <p class="tbx-hero-lite__text"><?php echo esc_html($slide['text']); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($slide['url']) && !empty($slide['label'])) : ?>
                                <a class="tbx-hero-lite__btn" href="<?php echo esc_url($slide['url']); ?>"><?php echo esc_html($slide['label']); ?></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>

            <?php if (count($slides) > 1) : ?>
                <div class="tbx-hero-lite__nav">
                    <button type="button" class="tbx-hero-lite__arrow tbx-hero-lite__prev" aria-label="Anterior">‹</button>
                    <button type="button" class="tbx-hero-lite__arrow tbx-hero-lite__next" aria-label="Siguiente">›</button>
                </div>
                <div class="tbx-hero-lite__dots" aria-label="Paginación del slider">
                    <?php foreach ($slides as $i => $slide) : ?>
                        <button type="button" class="tbx-hero-lite__dot" data-index="<?php echo esc_attr((string) ($i + 1)); ?>" aria-label="Ir al slide <?php echo esc_attr((string) ($i + 1)); ?>"></button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /* ---------- Panel ---------- */

    public static function admin_assets(string $hook): void
    {
        if (!str_contains($hook, 'tibox-admin-slider')) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('tbx-admin-slider', TBX_ADMIN_URL . 'assets/admin-slider.js', ['jquery'], TBX_ADMIN_VERSION, true);
    }

    public static function render_admin(): void
    {
        if (isset($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Slider guardado.</p></div>';
        }

        $slides = self::slides();
        while (count($slides) < self::MAX_SLIDES) {
            $slides[] = ['title' => '', 'text' => '', 'image' => '', 'mobile_image' => '', 'url' => '', 'label' => ''];
        }

        echo '<p class="tbx-lead">Slides del shortcode <code>[tbx_hero_lite]</code>. Se muestran en este orden. Deja una fila vacía para no usarla. En el título puedes usar <code>&lt;br&gt;</code> para cortar la línea.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('tbx_save_slider');
        echo '<input type="hidden" name="action" value="tbx_save_slider">';
        echo '<div class="tbx-slides">';

        foreach ($slides as $i => $s) {
            $n = $i + 1;
            echo '<fieldset class="tbx-slide">';
            echo '<legend>Slide ' . $n . '</legend>';
            echo '<div class="tbx-slide__preview" style="' . (!empty($s['image']) ? 'background-image:url(' . esc_url($s['image']) . ')' : '') . '"></div>';
            echo '<div class="tbx-slide__fields">';
            printf('<label>Título<input type="text" name="slides[%1$d][title]" value="%2$s"></label>', $i, esc_attr($s['title']));
            printf('<label>Texto<textarea rows="2" name="slides[%1$d][text]">%2$s</textarea></label>', $i, esc_textarea($s['text']));
            printf('<label>Imagen escritorio<span class="tbx-media"><input type="url" name="slides[%1$d][image]" value="%2$s"><button type="button" class="button tbx-pick">Elegir</button></span></label>', $i, esc_attr($s['image']));
            printf('<label>Imagen móvil<span class="tbx-media"><input type="url" name="slides[%1$d][mobile_image]" value="%2$s"><button type="button" class="button tbx-pick">Elegir</button></span></label>', $i, esc_attr($s['mobile_image']));
            printf('<div class="tbx-row2"><label>Texto del botón<input type="text" name="slides[%1$d][label]" value="%2$s"></label><label>Enlace del botón<input type="url" name="slides[%1$d][url]" value="%3$s"></label></div>', $i, esc_attr($s['label']), esc_attr($s['url']));
            echo '</div></fieldset>';
        }

        echo '</div>';
        submit_button('Guardar slider');
        echo '</form>';
    }

    public static function save(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('No autorizado');
        }
        check_admin_referer('tbx_save_slider');

        $in = isset($_POST['slides']) && is_array($_POST['slides']) ? wp_unslash($_POST['slides']) : [];
        $out = [];
        foreach (array_slice($in, 0, self::MAX_SLIDES) as $s) {
            $row = [
                'title' => wp_kses((string) ($s['title'] ?? ''), ['br' => []]),
                'text' => sanitize_text_field($s['text'] ?? ''),
                'image' => esc_url_raw($s['image'] ?? ''),
                'mobile_image' => esc_url_raw($s['mobile_image'] ?? ''),
                'url' => esc_url_raw($s['url'] ?? ''),
                'label' => sanitize_text_field($s['label'] ?? ''),
            ];
            if ($row['image'] !== '' || $row['title'] !== '') {
                $out[] = $row;
            }
        }
        update_option(self::OPTION, $out, true);
        wp_safe_redirect(add_query_arg('updated', '1', TBX_Admin::tab_url('slider')));
        exit;
    }
}

TBX_Slider::init();
