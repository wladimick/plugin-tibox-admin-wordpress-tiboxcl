<?php
/* Portado desde el snippet de WPCode del mismo nombre. Tibox Admin 1.0.0 */
/**
 * TIBOX — Importador de proyecto ZIP (Claude Design)
 *
 * Objetivo:
 * Permitir subir el "Project archive" (.zip) exportado desde Claude
 * Design directamente en una Landing, sin pasar por el textarea de
 * "Documento HTML completo". Evita el problema de archivos HTML
 * gigantes (imágenes en base64 pueden inflar un solo archivo a
 * decenas de MB) y evita los límites de post_max_size de WordPress,
 * ya que el .zip se sube como archivo (upload), no como texto de
 * formulario.
 *
 * Funcionamiento:
 * 1. Agrega un campo "Subir proyecto ZIP" en el editor de Landing.
 * 2. Al guardar, descomprime el .zip en:
 *    wp-content/uploads/tibox-landings/{post_id}/
 * 3. Busca el archivo principal (index.html, o el primer .html en la
 *    raíz del zip).
 * 4. Sirve ese archivo en la URL bonita de la landing (/lp/slug/),
 *    inyectando <base href="..."> para que las rutas relativas de
 *    CSS, JS e imágenes sigan funcionando.
 *
 * Requiere que el modo de la landing sea "Documento HTML completo".
 * Si subes un .zip, este importador tiene prioridad sobre el texto
 * pegado en el campo "Documento HTML completo".
 *
 * IMPORTANTE: solo administradores pueden subir el .zip (misma
 * restricción que el resto del gestor de landings).
 */
if (!defined('ABSPATH')) {
    exit;
}
if (!class_exists('TIBOX_Landing_Zip_Importer', false)) {

final class TIBOX_Landing_Zip_Importer
{
    private const POST_TYPE = 'tibox_landing';
    private const META_ZIP_FOLDER = '_tibox_lp_zip_folder';
    private const META_ZIP_ENTRY = '_tibox_lp_zip_entry';
    private const META_ZIP_ORIGINAL_NAME = '_tibox_lp_zip_original_name';
    private const UPLOAD_SUBDIR = 'tibox-landings';
    private const NONCE_ACTION = 'tibox_lp_zip_save';
    private const NONCE_FIELD = 'tibox_lp_zip_nonce';
    public static function init(): void
    {
        /*
         * El formulario de edición de WordPress no incluye
         * enctype="multipart/form-data" por defecto, así que sin
         * esto el navegador nunca envía el contenido del archivo ZIP
         * seleccionado (el campo se ve lleno, pero $_FILES llega
         * vacío al servidor).
         */
        add_action('post_edit_form_tag', [self::class, 'add_multipart_enctype']);
        add_action('add_meta_boxes', [self::class, 'add_meta_box']);
        add_action('save_post_' . self::POST_TYPE, [self::class, 'handle_upload']);
        /*
         * Prioridad -5: se ejecuta antes que el renderizado normal
         * del gestor de landings (que corre en prioridad 0), para
         * poder interceptar la respuesta si existe un proyecto ZIP.
         */
        add_action('template_redirect', [self::class, 'maybe_render_zip'], -5);
        /*
         * Registrado siempre (no solo dentro de handle_upload), para
         * que el aviso sobreviva a la redirección que WordPress hace
         * después de guardar/publicar.
         */
        add_action('admin_notices', [self::class, 'display_stored_errors']);
    }
    public static function add_multipart_enctype(WP_Post $post): void
    {
        if ($post->post_type !== self::POST_TYPE) {
            return;
        }
        echo ' enctype="multipart/form-data"';
    }
    public static function add_meta_box(): void
    {
        add_meta_box(
            'tibox-lp-zip-import',
            'Importar proyecto ZIP (Claude Design)',
            [self::class, 'render_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }
    public static function render_meta_box(WP_Post $post): void
    {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_FIELD);
        $folder = get_post_meta($post->ID, self::META_ZIP_FOLDER, true);
        $entry = get_post_meta($post->ID, self::META_ZIP_ENTRY, true);
        $original_name = get_post_meta($post->ID, self::META_ZIP_ORIGINAL_NAME, true);
        ?>
        <p>
            Sube aquí el archivo <code>.zip</code> generado desde
            Claude Design (Share &rarr; Project HTML &rarr;
            <strong>Project archive</strong>). Esta opción reemplaza
            al campo "Documento HTML completo" cuando hay un proyecto
            cargado.
        </p>
        <p>
            <input type="file" name="tibox_lp_zip_file" accept=".zip">
        </p>
        <?php if ($folder !== '' && $entry !== '') : ?>
            <div style="
                margin-top: 10px;
                padding: 10px 14px;
                background: #f0f6fc;
                border: 1px solid #2271b1;
                border-radius: 4px;
            ">
                <strong>Proyecto cargado actualmente:</strong><br>
                <?php if ($original_name !== '') : ?>
                    Archivo original: <code><?php echo esc_html($original_name); ?></code><br>
                <?php endif; ?>
                Punto de entrada detectado: <code><?php echo esc_html($entry); ?></code><br>
                <label style="display:block;margin-top:8px;">
                    <input type="checkbox" name="tibox_lp_zip_remove" value="1">
                    Quitar proyecto ZIP y volver a usar el campo
                    "Documento HTML completo".
                </label>
            </div>
        <?php endif; ?>
        <p class="description">
            El .zip se descomprime en el servidor al publicar o
            actualizar. Si vuelves a subir un .zip, reemplaza al
            anterior.
        </p>
        <?php
    }
    public static function handle_upload(int $post_id): void
    {
        if (
            !isset($_POST[self::NONCE_FIELD]) ||
            !wp_verify_nonce(
                sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD])),
                self::NONCE_ACTION
            )
        ) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post_id)) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        /*
         * Opción: quitar el proyecto ZIP actual.
         */
        if (!empty($_POST['tibox_lp_zip_remove'])) {
            self::delete_existing_folder($post_id);
            delete_post_meta($post_id, self::META_ZIP_FOLDER);
            delete_post_meta($post_id, self::META_ZIP_ENTRY);
            delete_post_meta($post_id, self::META_ZIP_ORIGINAL_NAME);
            return;
        }
        if (
            !isset($_FILES['tibox_lp_zip_file']) ||
            !is_array($_FILES['tibox_lp_zip_file']) ||
            (int) $_FILES['tibox_lp_zip_file']['error'] === UPLOAD_ERR_NO_FILE
        ) {
            return;
        }
        if ((int) $_FILES['tibox_lp_zip_file']['error'] !== UPLOAD_ERR_OK) {
            self::add_admin_error(
                'No fue posible subir el archivo ZIP (código de error: ' .
                (int) $_FILES['tibox_lp_zip_file']['error'] . ').'
            );
            return;
        }
        $original_name = sanitize_file_name(
            wp_unslash($_FILES['tibox_lp_zip_file']['name'])
        );
        if (strtolower(pathinfo($original_name, PATHINFO_EXTENSION)) !== 'zip') {
            self::add_admin_error('El archivo debe ser un .zip.');
            return;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        add_filter('upload_mimes', [self::class, 'allow_zip_mime']);
        $overrides = [
            'test_form' => false,
            'mimes' => ['zip' => 'application/zip'],
        ];
        $uploaded = wp_handle_upload($_FILES['tibox_lp_zip_file'], $overrides);
        remove_filter('upload_mimes', [self::class, 'allow_zip_mime']);
        if (isset($uploaded['error'])) {
            self::add_admin_error(
                'Error al subir el ZIP: ' . $uploaded['error']
            );
            return;
        }
        $zip_path = $uploaded['file'];
        $target_dir = self::get_extract_dir($post_id);
        self::delete_existing_folder($post_id);
        wp_mkdir_p($target_dir);
        if (!class_exists('ZipArchive')) {
            self::add_admin_error(
                'El servidor no tiene la extensión PHP ZipArchive habilitada. ' .
                'Pide a tu hosting que la active para poder usar esta opción.'
            );
            @unlink($zip_path);
            return;
        }
        $zip = new ZipArchive();
        if ($zip->open($zip_path) !== true) {
            self::add_admin_error('No fue posible abrir el archivo ZIP.');
            @unlink($zip_path);
            return;
        }
        $zip->extractTo($target_dir);
        $zip->close();
        /*
         * El .zip original ya no se necesita una vez descomprimido.
         */
        @unlink($zip_path);
        $entry = self::find_entry_html($target_dir);
        if ($entry === '') {
            self::add_admin_error(
                'No se encontró un archivo .html dentro del ZIP ' .
                '(se esperaba un index.html en la raíz del proyecto).'
            );
            self::delete_existing_folder($post_id);
            return;
        }
        update_post_meta($post_id, self::META_ZIP_FOLDER, basename($target_dir));
        update_post_meta($post_id, self::META_ZIP_ENTRY, $entry);
        update_post_meta($post_id, self::META_ZIP_ORIGINAL_NAME, $original_name);
    }
    public static function allow_zip_mime(array $mimes): array
    {
        $mimes['zip'] = 'application/zip';
        return $mimes;
    }
    private static function get_extract_dir(int $post_id): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['basedir']) .
            self::UPLOAD_SUBDIR . '/' . $post_id;
    }
    private static function get_extract_url(int $post_id): string
    {
        $upload_dir = wp_upload_dir();
        return trailingslashit($upload_dir['baseurl']) .
            self::UPLOAD_SUBDIR . '/' . $post_id;
    }
    private static function delete_existing_folder(int $post_id): void
    {
        $dir = self::get_extract_dir($post_id);
        if (!is_dir($dir)) {
            return;
        }
        self::rrmdir($dir);
    }
    private static function rrmdir(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
    /**
     * Busca el archivo HTML principal dentro de la carpeta extraída.
     * Prioriza "index.html" en la raíz; si no existe, usa el primer
     * .html que encuentre (incluye una carpeta contenedora, ya que
     * algunos exports de Claude Design agregan una subcarpeta única).
     */
    private static function find_entry_html(string $dir): string
    {
        $index_at_root = $dir . '/index.html';
        if (file_exists($index_at_root)) {
            return 'index.html';
        }
        $items = scandir($dir);
        if ($items === false) {
            return '';
        }
        $only_dir = null;
        $html_at_root = '';
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path) && $only_dir === null) {
                $only_dir = $item;
            } elseif (
                is_file($path) &&
                strtolower(pathinfo($item, PATHINFO_EXTENSION)) === 'html' &&
                $html_at_root === ''
            ) {
                $html_at_root = $item;
            }
        }
        if ($html_at_root !== '') {
            return $html_at_root;
        }
        /*
         * El zip contenía una única carpeta (común en exports que
         * empaquetan todo dentro de una carpeta con el nombre del
         * proyecto). Buscamos el index.html un nivel más adentro.
         */
        if ($only_dir !== null) {
            if (file_exists($dir . '/' . $only_dir . '/index.html')) {
                return $only_dir . '/index.html';
            }
        }
        return '';
    }
    public static function maybe_render_zip(): void
    {
        if (is_admin() || !is_singular(self::POST_TYPE) || is_feed() || is_embed()) {
            return;
        }
        $post_id = get_queried_object_id();
        $entry = get_post_meta($post_id, self::META_ZIP_ENTRY, true);
        if ($entry === '') {
            /*
             * No hay proyecto ZIP cargado: dejamos que el gestor de
             * landings normal (Documento HTML completo / Canvas)
             * se encargue en su hook de prioridad 0.
             */
            return;
        }
        $dir = self::get_extract_dir($post_id);
        $file_path = $dir . '/' . $entry;
        if (!file_exists($file_path)) {
            return;
        }
        $html = file_get_contents($file_path);
        if ($html === false) {
            return;
        }
        $entry_dir_url = trailingslashit(
            self::get_extract_url($post_id) .
            (str_contains($entry, '/') ? '/' . dirname($entry) : '')
        );
        $base_tag = '<base href="' . esc_url($entry_dir_url) . '">';
        if (stripos($html, '<base ') === false) {
            if (stripos($html, '<head>') !== false) {
                $html = preg_replace(
                    '/<head(\s[^>]*)?>/i',
                    '$0' . $base_tag,
                    $html,
                    1
                );
            } else {
                $html = $base_tag . $html;
            }
        }
        status_header(200);
        header('Content-Type: text/html; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }
    private static function add_admin_error(string $message): void
    {
        set_transient(
            'tibox_lp_zip_error_' . get_current_user_id(),
            $message,
            60
        );
    }
    /**
     * Muestra (y luego borra) el último error guardado por
     * add_admin_error(). Se registra siempre en 'admin_notices', así
     * que sobrevive a la redirección posterior a guardar/publicar.
     */
    public static function display_stored_errors(): void
    {
        $key = 'tibox_lp_zip_error_' . get_current_user_id();
        $message = get_transient($key);
        if ($message === false || $message === '') {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error"><p><strong>Importador ZIP (Claude Design):</strong> ' .
            esc_html($message) .
            '</p></div>';
    }
}
TIBOX_Landing_Zip_Importer::init();

}
