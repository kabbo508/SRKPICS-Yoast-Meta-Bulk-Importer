<?php
/**
 * Plugin Name: SRKPICS Yoast Meta Bulk Importer
 * Description: Bulk import Yoast SEO meta titles and meta descriptions for posts, pages, WooCommerce products, categories, brands, vendors, and other taxonomies using a CSV file.
 * Version: 1.0.0
 * Author: SRKPICS
 * Author URI: https://sumonrahmankabbo.com/
 * Text Domain: srkpics-yoast-meta-bulk-importer
 */

if (!defined('ABSPATH')) {
    exit;
}

final class SRKPICS_Yoast_Meta_Bulk_Importer {
    const NONCE_ACTION = 'srkpics_y mbi_nonce_action';
    const NONCE_NAME   = 'srkpics_ymbi_nonce';
    const TRANSIENT_PREFIX = 'srkpics_ymbi_job_';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
        add_action('wp_ajax_srkpics_ymbi_upload_csv', array($this, 'ajax_upload_csv'));
        add_action('wp_ajax_srkpics_ymbi_process_batch', array($this, 'ajax_process_batch'));
    }

    public function admin_menu() {
        add_management_page(
            __('SRKPICS Yoast Meta Importer', 'srkpics-yoast-meta-bulk-importer'),
            __('Yoast Meta Importer', 'srkpics-yoast-meta-bulk-importer'),
            'manage_options',
            'srkpics-yoast-meta-importer',
            array($this, 'admin_page')
        );
    }

    public function admin_assets($hook) {
        if ($hook !== 'tools_page_srkpics-yoast-meta-importer') {
            return;
        }

        wp_enqueue_style(
            'srkpics-ymbi-admin',
            plugins_url('assets/admin.css', __FILE__),
            array(),
            null
        );

        wp_enqueue_script(
            'srkpics-ymbi-admin',
            plugins_url('assets/admin.js', __FILE__),
            array('jquery'),
            null,
            true
        );

        wp_localize_script('srkpics-ymbi-admin', 'SRKPICS_YMBI', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce(self::NONCE_ACTION),
            'batch_size' => 20,
        ));
    }

    public function admin_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap srkpics-ymbi-wrap">
            <h1><?php esc_html_e('SRKPICS Yoast Meta Bulk Importer', 'srkpics-yoast-meta-bulk-importer'); ?></h1>
            <p class="description">Upload a CSV file and update Yoast SEO meta titles and descriptions in bulk without changing URLs.</p>

            <div class="srkpics-ymbi-card">
                <h2>CSV Format</h2>
                <p>Your CSV should include these columns:</p>
                <code>URL</code>, <code>Meta Title</code>, <code>Meta Description</code>
                <p class="description">Title and description are optional per row. If title is missing, only description will import. If description is missing, only title will import. If both exist, both will import.</p>
            </div>

            <div class="srkpics-ymbi-card">
                <h2>Upload CSV</h2>
                <form id="srkpics-ymbi-form" enctype="multipart/form-data">
                    <input type="file" name="csv_file" id="srkpics-ymbi-csv" accept=".csv,text/csv" required>
                    <button type="submit" class="button button-primary">Upload & Start Import</button>
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                </form>
            </div>

            <div class="srkpics-ymbi-status-panel">
                <div class="srkpics-ymbi-connection"><span></span> Ready</div>
                <div class="srkpics-ymbi-progress"><div></div></div>
                <div class="srkpics-ymbi-counts">
                    <strong>Total:</strong> <span id="ymbi-total">0</span>
                    <strong>Processed:</strong> <span id="ymbi-processed">0</span>
                    <strong>Updated:</strong> <span id="ymbi-updated">0</span>
                    <strong>Skipped:</strong> <span id="ymbi-skipped">0</span>
                    <strong>Failed:</strong> <span id="ymbi-failed">0</span>
                </div>
            </div>

            <div class="srkpics-ymbi-card">
                <h2>Live Log</h2>
                <div id="srkpics-ymbi-log" class="srkpics-ymbi-log">Waiting for CSV upload...</div>
            </div>
        </div>
        <?php
    }

    public function ajax_upload_csv() {
        $this->verify_request();

        if (empty($_FILES['csv_file']['tmp_name'])) {
            wp_send_json_error(array('message' => 'No CSV file uploaded.'));
        }

        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            wp_send_json_error(array('message' => 'Please upload a valid CSV file.'));
        }

        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . 'srkpics-yoast-meta-importer/';
        if (!wp_mkdir_p($target_dir)) {
            wp_send_json_error(array('message' => 'Could not create upload directory.'));
        }

        $job_id = wp_generate_uuid4();
        $target_file = $target_dir . 'import-' . $job_id . '.csv';

        if (!move_uploaded_file($file['tmp_name'], $target_file)) {
            wp_send_json_error(array('message' => 'Could not save uploaded CSV.'));
        }

        $total = $this->count_csv_rows($target_file);
        if ($total < 1) {
            wp_send_json_error(array('message' => 'CSV file has no data rows.'));
        }

        set_transient(self::TRANSIENT_PREFIX . $job_id, array(
            'file' => $target_file,
            'total' => $total,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
        ), DAY_IN_SECONDS);

        wp_send_json_success(array(
            'message' => 'CSV uploaded successfully. Import started.',
            'job_id' => $job_id,
            'total' => $total,
        ));
    }

    public function ajax_process_batch() {
        $this->verify_request();

        $job_id = isset($_POST['job_id']) ? sanitize_text_field(wp_unslash($_POST['job_id'])) : '';
        $offset = isset($_POST['offset']) ? max(0, absint($_POST['offset'])) : 0;
        $limit  = isset($_POST['limit']) ? min(100, max(1, absint($_POST['limit']))) : 20;

        $job = get_transient(self::TRANSIENT_PREFIX . $job_id);
        if (!$job || empty($job['file']) || !file_exists($job['file'])) {
            wp_send_json_error(array('message' => 'Import job not found or expired.'));
        }

        $rows = $this->read_csv_batch($job['file'], $offset, $limit);
        $logs = array();
        $updated = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($rows as $row_number => $row) {
            $result = $this->process_row($row, $row_number);
            $logs[] = $result['message'];
            if ($result['status'] === 'updated') {
                $updated++;
            } elseif ($result['status'] === 'skipped') {
                $skipped++;
            } else {
                $failed++;
            }
        }

        $job['updated'] += $updated;
        $job['skipped'] += $skipped;
        $job['failed']  += $failed;
        set_transient(self::TRANSIENT_PREFIX . $job_id, $job, DAY_IN_SECONDS);

        $new_offset = $offset + count($rows);
        $done = $new_offset >= (int) $job['total'];

        if ($done) {
            @unlink($job['file']);
            delete_transient(self::TRANSIENT_PREFIX . $job_id);
        }

        wp_send_json_success(array(
            'logs' => $logs,
            'processed' => $new_offset,
            'total' => (int) $job['total'],
            'updated' => (int) $job['updated'],
            'skipped' => (int) $job['skipped'],
            'failed' => (int) $job['failed'],
            'done' => $done,
        ));
    }

    private function verify_request() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied.'));
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh and try again.'));
        }
    }

    private function count_csv_rows($file) {
        $count = 0;
        if (($handle = fopen($file, 'r')) !== false) {
            fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== false) {
                if (!empty(array_filter($data))) {
                    $count++;
                }
            }
            fclose($handle);
        }
        return $count;
    }

    private function read_csv_batch($file, $offset, $limit) {
        $rows = array();
        if (($handle = fopen($file, 'r')) === false) {
            return $rows;
        }

        $headers = fgetcsv($handle);
        if (!$headers) {
            fclose($handle);
            return $rows;
        }
        $headers = array_map(array($this, 'normalize_header'), $headers);

        $current = 0;
        $row_number = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $row_number++;
            if (empty(array_filter($data))) {
                continue;
            }
            if ($current < $offset) {
                $current++;
                continue;
            }
            if (count($rows) >= $limit) {
                break;
            }

            $row = array();
            foreach ($headers as $index => $header) {
                $row[$header] = isset($data[$index]) ? $data[$index] : '';
            }
            $rows[$row_number] = $row;
            $current++;
        }
        fclose($handle);
        return $rows;
    }

    private function normalize_header($header) {
        $header = strtolower(trim((string) $header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header);
        $header = trim($header, '_');

        $map = array(
            'url' => 'url',
            'link' => 'url',
            'page_url' => 'url',
            'product_url' => 'url',
            'meta_title' => 'meta_title',
            'seo_title' => 'meta_title',
            'title' => 'meta_title',
            'yoast_title' => 'meta_title',
            'meta_description' => 'meta_description',
            'description' => 'meta_description',
            'seo_description' => 'meta_description',
            'yoast_description' => 'meta_description',
            'metadesc' => 'meta_description',
        );

        return isset($map[$header]) ? $map[$header] : $header;
    }

    private function process_row($row, $row_number) {
        $url = isset($row['url']) ? esc_url_raw(trim($row['url'])) : '';
        $title = isset($row['meta_title']) ? trim(wp_unslash($row['meta_title'])) : '';
        $description = isset($row['meta_description']) ? trim(wp_unslash($row['meta_description'])) : '';

        if (!$url) {
            return array('status' => 'skipped', 'message' => "Row {$row_number}: Skipped, missing URL.");
        }
        if ($title === '' && $description === '') {
            return array('status' => 'skipped', 'message' => "Row {$row_number}: Skipped, no title or description for {$url}.");
        }

        $target = $this->resolve_url($url);
        if (!$target) {
            return array('status' => 'failed', 'message' => "Row {$row_number}: Failed, URL not found: {$url}");
        }

        $changed = array();
        if ($target['type'] === 'post') {
            if ($title !== '') {
                update_post_meta($target['id'], '_yoast_wpseo_title', sanitize_text_field($title));
                $changed[] = 'title';
            }
            if ($description !== '') {
                update_post_meta($target['id'], '_yoast_wpseo_metadesc', sanitize_textarea_field($description));
                $changed[] = 'description';
            }
            return array('status' => 'updated', 'message' => "Row {$row_number}: Updated " . implode(' and ', $changed) . " for post/product/page ID {$target['id']}.");
        }

        if ($target['type'] === 'term') {
            if ($title !== '') {
                update_term_meta($target['id'], '_yoast_wpseo_title', sanitize_text_field($title));
                $changed[] = 'title';
            }
            if ($description !== '') {
                update_term_meta($target['id'], '_yoast_wpseo_metadesc', sanitize_textarea_field($description));
                $changed[] = 'description';
            }
            $this->update_yoast_taxonomy_option($target['taxonomy'], $target['id'], $title, $description);
            return array('status' => 'updated', 'message' => "Row {$row_number}: Updated " . implode(' and ', $changed) . " for taxonomy {$target['taxonomy']} term ID {$target['id']}.");
        }

        return array('status' => 'failed', 'message' => "Row {$row_number}: Failed, unsupported URL target: {$url}");
    }

    private function resolve_url($url) {
        $post_id = $this->resolve_post_id($url);
        if ($post_id) {
            return array('type' => 'post', 'id' => $post_id);
        }

        $term = $this->resolve_term($url);
        if ($term) {
            return array('type' => 'term', 'id' => (int) $term->term_id, 'taxonomy' => $term->taxonomy);
        }

        return false;
    }

    private function resolve_post_id($url) {
        $candidates = array_unique(array(
            $url,
            trailingslashit($url),
            untrailingslashit($url),
            home_url(wp_parse_url($url, PHP_URL_PATH)),
            trailingslashit(home_url(wp_parse_url($url, PHP_URL_PATH))),
            untrailingslashit(home_url(wp_parse_url($url, PHP_URL_PATH))),
        ));

        foreach ($candidates as $candidate) {
            $post_id = url_to_postid($candidate);
            if ($post_id) {
                return (int) $post_id;
            }
        }
        return 0;
    }

    private function resolve_term($url) {
        $path = trim((string) wp_parse_url($url, PHP_URL_PATH), '/');
        if (!$path) {
            return false;
        }

        $parts = array_values(array_filter(explode('/', $path)));
        $slug = sanitize_title(end($parts));
        if (!$slug) {
            return false;
        }

        $public_taxonomies = get_taxonomies(array('public' => true), 'objects');
        $priority = array('product_cat', 'product_tag', 'product_brand', 'brand', 'pwb-brand', 'yith_product_brand', 'vendor', 'product_vendor');
        $taxonomies = array_unique(array_merge($priority, array_keys($public_taxonomies)));

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $terms = get_terms(array(
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'hide_empty' => false,
            ));
            if (is_wp_error($terms) || empty($terms)) {
                continue;
            }
            foreach ($terms as $term) {
                $term_link = get_term_link($term);
                if (is_wp_error($term_link)) {
                    continue;
                }
                if ($this->normalize_url_for_compare($term_link) === $this->normalize_url_for_compare($url)) {
                    return $term;
                }
            }
        }

        foreach ($taxonomies as $taxonomy) {
            if (!taxonomy_exists($taxonomy)) {
                continue;
            }
            $term = get_term_by('slug', $slug, $taxonomy);
            if ($term && !is_wp_error($term)) {
                return $term;
            }
        }

        return false;
    }

    private function normalize_url_for_compare($url) {
        $parts = wp_parse_url($url);
        $host = isset($parts['host']) ? strtolower($parts['host']) : '';
        $path = isset($parts['path']) ? '/' . trim($parts['path'], '/') . '/' : '/';
        return $host . $path;
    }

    private function update_yoast_taxonomy_option($taxonomy, $term_id, $title, $description) {
        $option = get_option('wpseo_taxonomy_meta');
        if (!is_array($option)) {
            $option = array();
        }
        if (!isset($option[$taxonomy]) || !is_array($option[$taxonomy])) {
            $option[$taxonomy] = array();
        }
        if (!isset($option[$taxonomy][$term_id]) || !is_array($option[$taxonomy][$term_id])) {
            $option[$taxonomy][$term_id] = array();
        }

        if ($title !== '') {
            $option[$taxonomy][$term_id]['wpseo_title'] = sanitize_text_field($title);
        }
        if ($description !== '') {
            $option[$taxonomy][$term_id]['wpseo_desc'] = sanitize_textarea_field($description);
        }

        update_option('wpseo_taxonomy_meta', $option, false);
    }
}

SRKPICS_Yoast_Meta_Bulk_Importer::instance();
