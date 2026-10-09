<?php
/**
 * Administration screens and source provenance metadata.
 *
 * @package ThumbnailSourceRepair
 */

namespace WTSR;

defined('ABSPATH') || exit;

final class Admin
{
    /** @var string */
    private $page_hook = '';

    public function __construct()
    {
        add_action('admin_menu', array($this, 'add_page'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('add_meta_boxes_post', array($this, 'add_source_metabox'));
        add_action('save_post_post', array($this, 'save_source'), 10, 2);
        add_action('admin_post_wtsr_save_settings', array($this, 'save_settings'));
        add_action('admin_notices', array($this, 'source_notice'));
    }

    public function add_page(): void
    {
        $this->page_hook = (string) add_management_page(
            'Perbaikan Thumbnail',
            'Perbaikan Thumbnail',
            'manage_options',
            'wtsr-thumbnail-repair',
            array($this, 'render_page')
        );
    }

    public function enqueue_assets(string $hook): void
    {
        if ($hook !== $this->page_hook || !current_user_can('manage_options')) {
            return;
        }

        $base = defined('WTSR_URL') ? WTSR_URL : plugin_dir_url(dirname(__DIR__) . '/thumbnail-source-repair.php');
        $version = defined('WTSR_VERSION') ? WTSR_VERSION : '1.0.0';
        wp_enqueue_style('wtsr-admin', $base . 'assets/admin.css', array(), $version);
        wp_enqueue_script('wtsr-admin', $base . 'assets/admin.js', array(), $version, true);
        wp_localize_script('wtsr-admin', 'WTSR_ADMIN', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wtsr_admin'),
        ));
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Anda tidak memiliki izin untuk mengelola perbaikan thumbnail.'));
        }

        $hosts = get_option('wtsr_trusted_hosts', array());
        $hosts = is_array($hosts) ? array_filter($hosts, 'is_string') : array();
        $settings_status = isset($_GET['wtsr_settings']) && is_string($_GET['wtsr_settings'])
            ? sanitize_key(wp_unslash($_GET['wtsr_settings'])) : '';
        ?>
        <div class="wrap wtsr-wrap">
            <h1>Perbaikan Thumbnail</h1>
            <p>Periksa gambar unggulan pada posting yang sudah diterbitkan. Gambar pengganti harus berasal dari isi posting, lampiran milik posting, atau halaman sumber yang Anda catat.</p>

            <?php if ('saved' === $settings_status) : ?>
                <div class="notice notice-success"><p>Daftar hostname tepercaya telah disimpan.</p></div>
            <?php elseif ('invalid' === $settings_status) : ?>
                <div class="notice notice-error"><p>Pengaturan tidak disimpan. Masukkan maksimal 100 hostname lengkap, satu per baris, tanpa protokol, port, jalur, atau wildcard. Contoh: cdn.example.com.</p></div>
            <?php endif; ?>

            <section class="wtsr-panel" aria-labelledby="wtsr-mode-title">
                <h2 id="wtsr-mode-title">Pemeriksaan dan tindakan</h2>
                <p>Pratinjau adalah pilihan awal dan tidak mengubah posting. Gangguan jaringan, izin, berkas, atau sumber yang tidak dapat diverifikasi akan ditandai <strong>Perlu dicoba lagi</strong> dan tidak menjadi alasan penghapusan.</p>
                <fieldset id="wtsr-mode-controls">
                    <legend class="screen-reader-text">Pilih tindakan</legend>
                    <label class="wtsr-mode"><input type="radio" name="wtsr_mode" value="preview" checked> <strong>Pratinjau</strong> — hanya periksa dan tampilkan hasil.</label>
                    <label class="wtsr-mode"><input type="radio" name="wtsr_mode" value="repair"> <strong>Perbaiki saja</strong> — pasang gambar unggulan jika sumbernya dapat dibuktikan.</label>
                    <label class="wtsr-mode"><input type="radio" name="wtsr_mode" value="repair_delete"> <strong>Perbaiki + hapus permanen</strong> — coba pulihkan; hapus posting hanya jika semua sumber yang sah telah diperiksa dan gambar dipastikan tidak tersedia.</label>
                    <div id="wtsr-delete-confirmation" class="wtsr-danger" hidden>
                        <p><strong>Penghapusan permanen tidak dapat dibatalkan dan tidak masuk Sampah.</strong> Lampiran media tidak ikut dihapus. Cadangkan situs terlebih dahulu.</p>
                        <label><input type="checkbox" id="wtsr-confirm-delete" disabled> Saya memahami posting yang gagal dipulihkan akan dihapus permanen</label>
                    </div>
                </fieldset>
                <p class="wtsr-actions">
                    <button type="button" id="wtsr-run" class="button button-primary">Mulai pratinjau</button>
                    <button type="button" id="wtsr-stop" class="button" disabled>Berhenti</button>
                </p>
                <p id="wtsr-status" role="status" aria-live="polite">Siap memeriksa posting yang diterbitkan.</p>
                <div id="wtsr-error" class="notice notice-error inline" role="alert" hidden><p></p></div>
                <noscript><div class="notice notice-error inline"><p>Aktifkan JavaScript untuk menjalankan pemeriksaan bertahap.</p></div></noscript>
            </section>

            <section class="wtsr-panel" aria-labelledby="wtsr-results-title">
                <h2 id="wtsr-results-title">Hasil sesi ini</h2>
                <dl class="wtsr-counters">
                    <div><dt>Diperiksa</dt><dd id="wtsr-count-scanned">0</dd></div>
                    <div><dt>Sudah baik</dt><dd id="wtsr-count-healthy">0</dd></div>
                    <div><dt>Dapat diperbaiki</dt><dd id="wtsr-count-repairable">0</dd></div>
                    <div><dt>Diperbaiki</dt><dd id="wtsr-count-fixed">0</dd></div>
                    <div><dt>Tanpa sumber gambar</dt><dd id="wtsr-count-missing">0</dd></div>
                    <div><dt>Perlu dicoba lagi</dt><dd id="wtsr-count-retry">0</dd></div>
                    <div><dt>Dihapus permanen</dt><dd id="wtsr-count-deleted">0</dd></div>
                </dl>
                <p id="wtsr-empty">Hasil akan muncul setelah pemeriksaan. Posting dengan thumbnail yang sudah baik hanya masuk hitungan.</p>
                <div class="wtsr-table-container">
                    <table class="widefat striped wtsr-results">
                        <thead><tr><th scope="col">Posting</th><th scope="col">Status</th><th scope="col">Keterangan dan sumber</th></tr></thead>
                        <tbody id="wtsr-results"></tbody>
                    </table>
                </div>
            </section>

            <section class="wtsr-panel" aria-labelledby="wtsr-hosts-title">
                <h2 id="wtsr-hosts-title">Hostname CDN tepercaya</h2>
                <p>Untuk gambar dari halaman sumber, hostname halaman itu sendiri diizinkan. Tambahkan hostname CDN di sini hanya jika Anda telah memastikan CDN tersebut memang menyajikan gambar sumber Anda. Daftar ini tidak menyediakan gambar pengganti secara otomatis.</p>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" id="wtsr-settings-form">
                    <input type="hidden" name="action" value="wtsr_save_settings">
                    <?php wp_nonce_field('wtsr_save_settings', 'wtsr_settings_nonce'); ?>
                    <label for="wtsr-trusted-hosts">Satu hostname lengkap per baris, tanpa <code>https://</code>, wildcard, atau jalur.</label>
                    <textarea id="wtsr-trusted-hosts" name="wtsr_trusted_hosts" rows="5" class="large-text code" placeholder="cdn.example.com"><?php echo esc_textarea(implode("\n", $hosts)); ?></textarea>
                    <?php submit_button('Simpan hostname tepercaya', 'secondary', 'submit', false); ?>
                </form>
            </section>
        </div>
        <?php
    }

    public function save_settings(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html('Anda tidak memiliki izin untuk mengubah pengaturan ini.'), '', array('response' => 403));
        }
        check_admin_referer('wtsr_save_settings', 'wtsr_settings_nonce');

        $submitted = isset($_POST['wtsr_trusted_hosts']) && is_string($_POST['wtsr_trusted_hosts']);
        $raw = $submitted
            ? wp_unslash($_POST['wtsr_trusted_hosts']) : '';
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        $hosts = array();
        $valid = $submitted && strlen($raw) <= 26000;
        foreach ($lines as $line) {
            $host = strtolower(trim($line));
            if ('' === $host) {
                continue;
            }
            if (strlen($host) > 253 || false === strpos($host, '.')
                || filter_var($host, FILTER_VALIDATE_IP)
                || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || substr($host, -1) === '.') {
                $valid = false;
                break;
            }
            $hosts[] = $host;
        }
        $hosts = array_values(array_unique($hosts));
        $valid = $valid && count($hosts) <= 100;
        if ($valid) {
            update_option('wtsr_trusted_hosts', $hosts, false);
        }
        wp_safe_redirect(add_query_arg(array(
            'page'          => 'wtsr-thumbnail-repair',
            'wtsr_settings' => $valid ? 'saved' : 'invalid',
        ), admin_url('tools.php')));
        exit;
    }

    public function add_source_metabox(): void
    {
        add_meta_box('wtsr-source', 'Sumber asli untuk thumbnail', array($this, 'render_source_metabox'), 'post', 'side', 'default');
    }

    public function render_source_metabox(\WP_Post $post): void
    {
        wp_nonce_field('wtsr_save_source', 'wtsr_source_nonce');
        $source = get_post_meta($post->ID, '_wtsr_source_url', true);
        ?>
        <p><label for="wtsr-source-url">URL halaman sumber asli (HTTPS)</label></p>
        <input class="widefat" type="url" id="wtsr-source-url" name="wtsr_source_url" value="<?php echo esc_attr(is_string($source) ? $source : ''); ?>" placeholder="https://example.com/artikel" pattern="https://.*">
        <p class="description">Catat hanya halaman yang benar-benar menjadi sumber posting ini. Plugin membaca gambar artikel yang dinyatakan oleh halaman sumber tersebut. Gambar dari CDN memerlukan hostname tepercaya di Alat → Perbaikan Thumbnail.</p>
        <?php
    }

    public function save_source(int $post_id, \WP_Post $post): void
    {
        if ('post' !== $post->post_type || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id) || !current_user_can('edit_post', $post_id)
            || !isset($_POST['wtsr_source_nonce']) || !is_string($_POST['wtsr_source_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['wtsr_source_nonce'])), 'wtsr_save_source')
            || !isset($_POST['wtsr_source_url']) || !is_string($_POST['wtsr_source_url'])) {
            return;
        }

        $raw = trim(wp_unslash($_POST['wtsr_source_url']));
        if ('' === $raw) {
            delete_post_meta($post_id, '_wtsr_source_url');
            return;
        }
        $url = esc_url_raw($raw, array('https'));
        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['host']) || 'https' !== strtolower($parts['scheme'] ?? '')
            || isset($parts['user']) || isset($parts['pass']) || !filter_var($url, FILTER_VALIDATE_URL)) {
            set_transient('wtsr_source_error_' . get_current_user_id(), 1, MINUTE_IN_SECONDS);
            return;
        }
        update_post_meta($post_id, '_wtsr_source_url', $url);
    }

    public function source_notice(): void
    {
        $key = 'wtsr_source_error_' . get_current_user_id();
        if (!get_transient($key)) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error is-dismissible"><p>URL sumber thumbnail tidak disimpan. Gunakan URL HTTPS lengkap tanpa nama pengguna atau kata sandi. Sumber sebelumnya tetap tersimpan.</p></div>';
    }
}
