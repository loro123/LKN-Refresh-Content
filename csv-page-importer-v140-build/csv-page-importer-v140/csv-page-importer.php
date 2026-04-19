<?php
/**
 * Plugin Name: CSV Page Importer
 * Description: Import pages and posts from CSV with HTML content, scheduling, and Divi support. Uses PHP's native CSV parser.
 * Version: 1.4.0
 * Author: Your Name/Company
 */

if (!defined('ABSPATH')) {
    exit;
}

class CSV_Page_Importer_v140 {
    const VERSION = '1.4.0';
    const MENU_SLUG = 'csv-page-importer-v140';
    const META_KEY_SCHEMA = '_page_schema';

    private $errors = array();
    private $debug = array();
    private $stats = null;

    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        // Always register schema output — previously-imported pages should keep
        // emitting their schema regardless of whether an import has run this request.
        add_action('wp_head', array($this, 'output_schema'), 20);
        add_action('init', array($this, 'register_schema_meta'));
    }

    public function register_schema_meta() {
        $args = array(
            'show_in_rest'  => true,
            'single'        => true,
            'type'          => 'string',
            'auth_callback' => function () {
                return current_user_can('manage_options');
            },
        );
        register_post_meta('page', self::META_KEY_SCHEMA, $args);
        register_post_meta('post', self::META_KEY_SCHEMA, $args);
    }

    public function add_admin_menu() {
        add_submenu_page(
            'tools.php',
            'CSV Page Importer',
            'CSV Page Importer',
            'manage_options',
            self::MENU_SLUG,
            array($this, 'admin_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        if ('tools_page_' . self::MENU_SLUG !== $hook) {
            return;
        }
        wp_enqueue_style(
            'csv-importer-v140-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/admin.css',
            array(),
            self::VERSION
        );
        wp_enqueue_script(
            'csv-importer-v140-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/admin.js',
            array('jquery'),
            self::VERSION,
            true
        );
    }

    public function admin_page() {
        if (isset($_POST['csv_import_submit']) && isset($_FILES['csv_file'])) {
            $this->process_import();
        }
        ?>
        <div class="wrap">
            <h1>CSV Page Importer <small style="color:#888;font-weight:normal;">v<?php echo esc_html(self::VERSION); ?></small></h1>

            <?php if ($this->stats !== null) : ?>
                <div class="updated notice">
                    <p>
                        <strong>Import complete:</strong>
                        <?php echo (int) $this->stats['created']; ?> created,
                        <?php echo (int) $this->stats['updated']; ?> updated,
                        <?php echo (int) $this->stats['skipped']; ?> skipped,
                        <?php echo (int) $this->stats['failed']; ?> failed.
                    </p>
                </div>
            <?php endif; ?>

            <?php $this->render_error_log(); ?>

            <?php $this->render_form(); ?>
        </div>
        <?php
    }

    private function render_error_log() {
        if (empty($this->errors) && empty($this->debug)) {
            return;
        }
        echo '<div class="error-log">';
        if (!empty($this->errors)) {
            echo '<h2>Messages</h2><div class="error-messages">';
            foreach ($this->errors as $err) {
                echo '<div class="error-message">' . esc_html($err) . '</div>';
            }
            echo '</div>';
        }
        if (!empty($this->debug)) {
            echo '<h2>Debug Information</h2><div class="debug-log"><pre>';
            foreach ($this->debug as $line) {
                echo esc_html($line) . "\n";
            }
            echo '</pre></div>';
        }
        echo '</div>';
    }

    private function render_form() {
        $pages = get_pages(array('sort_column' => 'menu_order,post_title'));
        $divi_active = $this->is_divi_active();
        $library_items = $divi_active ? $this->get_divi_library_items() : array();
        ?>
        <div class="csv-import-form">
            <form method="post" enctype="multipart/form-data">
                <div class="form-section">
                    <h2>CSV File</h2>
                    <p class="description">
                        Required columns: <code>post_title</code>, <code>post_name</code>.
                        Optional: <code>post_content</code>, <code>h1_tag</code>, <code>post_type</code>, <code>post_date</code>, <code>post_status</code>.
                    </p>
                    <input type="file" name="csv_file" accept=".csv" required />
                </div>

                <div class="form-section">
                    <h2>Page Options</h2>

                    <div class="form-field">
                        <label for="default_post_type">Default Post Type:</label>
                        <select name="default_post_type" id="default_post_type">
                            <option value="page">Page</option>
                            <option value="post">Post</option>
                        </select>
                        <p class="description">Used when a row has no <code>post_type</code> column value.</p>
                    </div>

                    <div class="form-field">
                        <label for="parent_page">Parent Page:</label>
                        <select name="parent_page" id="parent_page">
                            <option value="0">None (top level)</option>
                            <?php foreach ($pages as $p) : ?>
                                <option value="<?php echo esc_attr($p->ID); ?>">
                                    <?php echo esc_html($p->post_title); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Only applied to rows imported as pages.</p>
                    </div>

                    <div class="form-field">
                        <label for="post_status">Default Status:</label>
                        <select name="post_status" id="post_status">
                            <option value="publish">Published</option>
                            <option value="draft">Draft</option>
                            <option value="pending">Pending Review</option>
                            <option value="private">Private</option>
                        </select>
                        <p class="description">Used when a row has no <code>post_status</code>. Future-dated rows auto-switch to <code>future</code>.</p>
                    </div>

                    <div class="form-field">
                        <label><input type="checkbox" name="include_content" value="1" checked /> Include content from CSV</label>
                    </div>

                    <div class="form-field">
                        <label><input type="checkbox" name="update_existing" value="1" /> Update existing pages/posts (matched by slug)</label>
                    </div>
                </div>

                <?php if ($divi_active) : ?>
                <div class="form-section">
                    <h2>Divi Options</h2>
                    <div class="form-field">
                        <label><input type="checkbox" name="use_divi" value="1" id="use_divi" /> Use Divi Builder</label>
                    </div>

                    <div id="divi-options" style="display:none;">
                        <div class="form-field">
                            <label><input type="checkbox" name="use_divi_library" value="1" id="use_divi_library" /> Use Divi Library Sections</label>
                        </div>

                        <div id="divi-library-options" style="display:none;">
                            <div class="form-field">
                                <label><input type="checkbox" name="use_header" value="1" id="use_header" /> Add Header/Hero Section</label>
                                <div id="header-options" style="display:none;margin-left:20px;margin-top:10px;">
                                    <label for="header_section">Select Header/Hero Section:</label>
                                    <select name="header_section" id="header_section">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($library_items as $item) : ?>
                                            <option value="<?php echo esc_attr($item->ID); ?>"><?php echo esc_html($item->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-field" style="margin-top:10px;">
                                        <label><input type="checkbox" name="skip_h1" value="1" checked /> Skip H1 tag when using Header/Hero</label>
                                    </div>
                                </div>
                            </div>

                            <div class="form-field">
                                <label><input type="checkbox" name="use_cta" value="1" id="use_cta" /> Add Call to Action Section</label>
                                <div id="cta-options" style="display:none;margin-left:20px;margin-top:10px;">
                                    <label for="cta_section">Select Call to Action Section:</label>
                                    <select name="cta_section" id="cta_section">
                                        <option value="">-- Select --</option>
                                        <?php foreach ($library_items as $item) : ?>
                                            <option value="<?php echo esc_attr($item->ID); ?>"><?php echo esc_html($item->post_title); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="form-section">
                    <h2>Advanced</h2>
                    <div class="form-field">
                        <label><input type="checkbox" name="debug_mode" value="1" /> Enable Debug Mode</label>
                    </div>
                </div>

                <div class="form-submit">
                    <?php wp_nonce_field('csv_import_nonce', 'csv_import_nonce'); ?>
                    <input type="submit" name="csv_import_submit" class="button button-primary" value="Import CSV" />
                </div>
            </form>
        </div>
        <?php
    }

    // ----------------------------------------------------------------------
    // IMPORT PROCESSING
    // ----------------------------------------------------------------------

    private function process_import() {
        if (!current_user_can('manage_options')) {
            $this->errors[] = 'You do not have permission to import pages.';
            return;
        }
        if (!isset($_POST['csv_import_nonce']) || !wp_verify_nonce($_POST['csv_import_nonce'], 'csv_import_nonce')) {
            $this->errors[] = 'Security check failed.';
            return;
        }
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = 'File upload failed (code ' . (isset($_FILES['csv_file']['error']) ? (int) $_FILES['csv_file']['error'] : 'unknown') . ').';
            return;
        }

        $ext = strtolower(pathinfo($_FILES['csv_file']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $this->errors[] = 'Invalid file type. Please upload a .csv file.';
            return;
        }

        $opts = $this->collect_options();
        $debug = !empty($opts['debug_mode']);

        $rows = $this->parse_csv_file($_FILES['csv_file']['tmp_name'], $debug);
        if ($rows === false || empty($rows)) {
            $this->errors[] = 'CSV file could not be parsed or is empty.';
            return;
        }

        $header_raw = array_shift($rows);
        if (!$header_raw || !is_array($header_raw)) {
            $this->errors[] = 'CSV file has no header row.';
            return;
        }
        $header = array_map(function ($h) {
            return strtolower(trim((string) $h));
        }, $header_raw);

        if ($debug) {
            $this->debug[] = 'Header columns: ' . wp_json_encode($header);
            $this->debug[] = 'Total data rows: ' . count($rows);
        }

        foreach (array('post_title', 'post_name') as $required) {
            if (!in_array($required, $header, true)) {
                $this->errors[] = "Required column '{$required}' is missing from the CSV header.";
                return;
            }
        }

        $col_idx = array();
        foreach (array('post_title', 'post_name', 'post_content', 'h1_tag', 'post_type', 'post_date', 'post_status') as $c) {
            $idx = array_search($c, $header, true);
            $col_idx[$c] = ($idx === false) ? null : $idx;
        }

        if ($debug) {
            $this->debug[] = 'Column indexes: ' . wp_json_encode($col_idx);
        }

        $valid_statuses = array('publish', 'draft', 'pending', 'future', 'private');
        $stats = array('created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0);
        $row_number = 1;

        foreach ($rows as $row) {
            $row_number++;

            if (!is_array($row)) {
                continue;
            }

            $non_empty = array_filter($row, function ($v) {
                return $v !== null && trim((string) $v) !== '';
            });
            if (empty($non_empty)) {
                if ($debug) $this->debug[] = "Row {$row_number}: empty row — skipped";
                continue;
            }

            $get = function ($key) use ($row, $col_idx) {
                $idx = $col_idx[$key];
                if ($idx === null || !isset($row[$idx])) {
                    return '';
                }
                return trim((string) $row[$idx]);
            };

            $post_title = $get('post_title');
            $post_name  = sanitize_title($get('post_name'));

            if ($post_title === '' || $post_name === '') {
                $this->errors[] = "Row {$row_number}: missing post_title or post_name — skipped.";
                $stats['skipped']++;
                continue;
            }

            // Determine post type
            $row_post_type = strtolower($get('post_type'));
            if ($row_post_type === '') {
                $row_post_type = $opts['default_post_type'];
            }
            if ($row_post_type !== 'page' && $row_post_type !== 'post') {
                $this->errors[] = "Row {$row_number}: invalid post_type '{$row_post_type}' (must be 'page' or 'post') — skipped.";
                $stats['skipped']++;
                continue;
            }

            // Determine status and date
            $row_status_raw = strtolower($get('post_status'));
            $row_status_explicit = ($row_status_raw !== '');
            if ($row_status_explicit && !in_array($row_status_raw, $valid_statuses, true)) {
                $this->errors[] = "Row {$row_number}: invalid post_status '{$row_status_raw}' — skipped.";
                $stats['skipped']++;
                continue;
            }

            $row_date_raw = $get('post_date');
            $row_date_ts = 0;
            $row_date_has = ($row_date_raw !== '');
            if ($row_date_has) {
                $row_date_ts = strtotime($row_date_raw);
                if ($row_date_ts === false) {
                    $this->errors[] = "Row {$row_number}: post_date '{$row_date_raw}' could not be parsed — skipped.";
                    $stats['skipped']++;
                    continue;
                }
            }

            $now = current_time('timestamp');
            $is_future = ($row_date_has && $row_date_ts > $now);

            $final_status = $row_status_explicit ? $row_status_raw : $opts['post_status'];

            // Auto-future logic
            if (!$row_status_explicit && $is_future) {
                $final_status = 'future';
            }

            // Explicit 'future' requires a future date
            if ($row_status_explicit && $row_status_raw === 'future' && !$is_future) {
                $this->errors[] = "Row {$row_number}: post_status 'future' requires a future post_date — skipped.";
                $stats['skipped']++;
                continue;
            }

            $post_content = $opts['include_content'] ? $get('post_content') : '';
            $h1_tag       = $get('h1_tag');

            if ($debug) {
                $this->debug[] = sprintf(
                    "Row %d: \"%s\" (slug=%s, type=%s, status=%s, date=%s, content=%d chars, h1=%d chars)",
                    $row_number,
                    $post_title,
                    $post_name,
                    $row_post_type,
                    $final_status,
                    $row_date_has ? date('Y-m-d H:i:s', $row_date_ts) : '-',
                    strlen($post_content),
                    strlen($h1_tag)
                );
            }

            $existing = get_page_by_path($post_name, OBJECT, $row_post_type);
            if ($existing && !$opts['update_existing']) {
                $this->errors[] = "Row {$row_number}: {$row_post_type} '{$post_title}' already exists — skipped.";
                $stats['skipped']++;
                continue;
            }

            $post_data = array(
                'post_title'  => $post_title,
                'post_name'   => $post_name,
                'post_status' => $final_status,
                'post_type'   => $row_post_type,
            );

            if ($row_post_type === 'page') {
                $post_data['post_parent'] = $opts['parent_id'];
            }

            if ($row_date_has) {
                $post_date_str = date('Y-m-d H:i:s', $row_date_ts);
                $post_data['post_date'] = $post_date_str;
                $post_data['post_date_gmt'] = get_gmt_from_date($post_date_str);
            }

            if ($opts['use_divi'] && $row_post_type === 'page') {
                $header_id = 0;
                $cta_id    = 0;
                $h1_for_divi = $h1_tag;

                if ($opts['use_divi_library']) {
                    if ($opts['use_header'] && $opts['header_section']) {
                        $header_id = $opts['header_section'];
                        if ($opts['skip_h1']) {
                            $h1_for_divi = '';
                        }
                    }
                    if ($opts['use_cta'] && $opts['cta_section']) {
                        $cta_id = $opts['cta_section'];
                    }
                }
                $post_data['post_content'] = $this->build_divi_content(
                    $post_content,
                    $h1_for_divi,
                    $header_id,
                    $cta_id
                );
            } else {
                if ($h1_tag !== '' && !preg_match('/<h1[\s>]/i', $post_content)) {
                    $post_data['post_content'] = $h1_tag . "\n\n" . $post_content;
                } else {
                    $post_data['post_content'] = $post_content;
                }
            }

            if ($existing) {
                $post_data['ID'] = $existing->ID;
                $post_id = wp_update_post(wp_slash($post_data), true);
                $action = 'updated';
            } else {
                $post_id = wp_insert_post(wp_slash($post_data), true);
                $action = 'created';
            }

            if (is_wp_error($post_id)) {
                $this->errors[] = "Row {$row_number}: " . $post_id->get_error_message();
                $stats['failed']++;
                continue;
            }

            if ($opts['use_divi'] && $row_post_type === 'page') {
                update_post_meta($post_id, '_et_pb_use_builder', 'on');
                update_post_meta($post_id, '_et_pb_show_page_creation', 'off');
            }

            $stats[$action]++;
        }

        $this->stats = $stats;
    }

    private function collect_options() {
        $default_post_type = isset($_POST['default_post_type']) ? sanitize_text_field($_POST['default_post_type']) : 'page';
        if ($default_post_type !== 'page' && $default_post_type !== 'post') {
            $default_post_type = 'page';
        }
        $post_status = isset($_POST['post_status']) ? sanitize_text_field($_POST['post_status']) : 'publish';
        if (!in_array($post_status, array('publish', 'draft', 'pending', 'private'), true)) {
            $post_status = 'publish';
        }
        return array(
            'default_post_type' => $default_post_type,
            'parent_id'        => isset($_POST['parent_page']) ? intval($_POST['parent_page']) : 0,
            'post_status'      => $post_status,
            'include_content'  => !empty($_POST['include_content']),
            'update_existing'  => !empty($_POST['update_existing']),
            'use_divi'         => !empty($_POST['use_divi']),
            'use_divi_library' => !empty($_POST['use_divi_library']),
            'use_header'       => !empty($_POST['use_header']),
            'header_section'   => isset($_POST['header_section']) ? intval($_POST['header_section']) : 0,
            'skip_h1'          => !empty($_POST['skip_h1']),
            'use_cta'          => !empty($_POST['use_cta']),
            'cta_section'      => isset($_POST['cta_section']) ? intval($_POST['cta_section']) : 0,
            'debug_mode'       => !empty($_POST['debug_mode']),
        );
    }

    // ----------------------------------------------------------------------
    // CSV PARSING — uses PHP's native fgetcsv via in-memory stream
    // ----------------------------------------------------------------------

    private function parse_csv_file($path, $debug) {
        $content = file_get_contents($path);
        if ($content === false) {
            $this->errors[] = 'Failed to read uploaded CSV file.';
            return false;
        }

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
            if ($debug) $this->debug[] = 'UTF-8 BOM detected and stripped.';
        }

        $content = str_replace(array("\r\n", "\r"), "\n", $content);

        if ($debug) $this->debug[] = 'File size: ' . strlen($content) . ' bytes (after normalization).';

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            $this->errors[] = 'Failed to create memory stream for CSV parsing.';
            return false;
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = array();
        while (($row = @fgetcsv($stream, 0, ',', '"', '')) !== false) {
            if (count($row) === 1 && ($row[0] === null || $row[0] === '')) {
                continue;
            }
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    // ----------------------------------------------------------------------
    // SCHEMA OUTPUT (read-only; import no longer writes schema)
    // ----------------------------------------------------------------------

    private function is_valid_json($str) {
        if ($str === '' || $str === null) return false;
        json_decode($str);
        return json_last_error() === JSON_ERROR_NONE;
    }

    public function output_schema() {
        if (!is_singular(array('page', 'post'))) {
            return;
        }
        global $post;
        if (!$post || empty($post->ID)) {
            return;
        }
        $schema = get_post_meta($post->ID, self::META_KEY_SCHEMA, true);
        if (empty($schema)) {
            return;
        }
        if (!$this->is_valid_json($schema)) {
            return;
        }
        $safe = str_replace('</', '<\/', $schema);
        echo "\n<!-- Page Schema (CSV Page Importer) -->\n";
        echo '<script type="application/ld+json">' . $safe . '</script>' . "\n";
    }

    // ----------------------------------------------------------------------
    // DIVI INTEGRATION
    // ----------------------------------------------------------------------

    private function is_divi_active() {
        $theme = wp_get_theme();
        $parent = $theme->parent();
        if ($theme->get('Name') === 'Divi' || ($parent && $parent->get('Name') === 'Divi')) {
            return true;
        }
        if (function_exists('is_plugin_active') && is_plugin_active('divi-builder/divi-builder.php')) {
            return true;
        }
        return false;
    }

    private function get_divi_library_items() {
        return get_posts(array(
            'post_type'      => 'et_pb_layout',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ));
    }

    private function get_divi_library_content($library_id) {
        $p = get_post($library_id);
        return $p ? $p->post_content : '';
    }

    private function build_divi_content($content, $h1_tag, $header_section_id = 0, $cta_section_id = 0) {
        $out = '';
        if ($header_section_id) {
            $out .= $this->get_divi_library_content($header_section_id);
        }
        $out .= '[et_pb_section admin_label="Content Section" _builder_version="4.16" custom_padding="50px||50px||false|false" global_colors_info="{}"]';
        $out .= '[et_pb_row _builder_version="4.16" global_colors_info="{}"]';
        $out .= '[et_pb_column type="4_4" _builder_version="4.16" global_colors_info="{}"]';
        $out .= '[et_pb_text _builder_version="4.16" global_colors_info="{}"]';
        if (!empty($h1_tag)) {
            $out .= $h1_tag;
        }
        $out .= $content;
        $out .= '[/et_pb_text][/et_pb_column][/et_pb_row][/et_pb_section]';
        if ($cta_section_id) {
            $out .= $this->get_divi_library_content($cta_section_id);
        }
        return $out;
    }
}

new CSV_Page_Importer_v140();
