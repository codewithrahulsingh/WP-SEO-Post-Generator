<?php
/*
Plugin Name: SEO Post Generator
Description: Generate SEO-optimized post content and categories using xAI API, and publish directly from the admin panel. Includes Table of Contents, Paragraph Generator, and Postmeta Import/Export.
Version: 2.5.0
Author: You
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('SEO_POST_GENERATOR_API_URL', 'https://api.x.ai/v1/chat/completions');
define('SEO_POST_GENERATOR_KYC_YEAR', 2025);
define('SEO_POST_GENERATOR_TRANSIENT_PREFIX', 'seo_post_generator_');
define('SEO_POST_GENERATOR_API_KEY', 'YOUR_API_KEY');

// Load sensitive data from wp-config.php
if (!defined('SEO_POST_GENERATOR_API_KEY')) {
    define('SEO_POST_GENERATOR_API_KEY', 'YOUR_API_KEY'); // Define in wp-config.php
}
if (!defined('SITE2_DB_HOST')) {
    define('SITE2_DB_HOST', 'localhost:3306'); // Define in wp-config.php
}
if (!defined('SITE2_DB_NAME')) {
    define('SITE2_DB_NAME', 'YOUR_DB_NAME'); // Define in wp-config.php
}
if (!defined('SITE2_DB_USER')) {
    define('SITE2_DB_USER', 'YOUR_DB_USER'); // Define in wp-config.php
}
if (!defined('SITE2_DB_PASSWORD')) {
    define('SITE2_DB_PASSWORD', 'YOUR_DB_PASSWORD'); // Define in wp-config.php
}
if (!defined('SITE2_DB_PREFIX')) {
    define('SITE2_DB_PREFIX', 'YOUR_DB_PREFIX'); // Table prefix for Site 2
}

/**
 * Initialize plugin
 */
class SEOPostGenerator {
    private static $site2_db;

    /**
     * Initialize Site 2 database connection
     */
    private static function init_site2_db() {
        if (!self::$site2_db) {
            self::$site2_db = new wpdb(
                SITE2_DB_USER,
                SITE2_DB_PASSWORD,
                SITE2_DB_NAME,
                SITE2_DB_HOST
            );
            if (self::$site2_db->last_error) {
                error_log('SEO Post Generator: Failed to connect to Site 2 DB: ' . self::$site2_db->last_error);
            }
        }
        return self::$site2_db;
    }

    /**
     * Initialize hooks
     */
    public static function init() {
        add_action('admin_menu', [__CLASS__, 'add_admin_menu']);
        add_action('admin_init', [__CLASS__, 'handle_requests']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    /**
     * Add admin menu page
     */
    public static function add_admin_menu() {
        add_menu_page(
            __('SEO Post Generator', 'seo-post-generator'),
            __('SEO Post Generator', 'seo-post-generator'),
            'manage_options',
            'seo-post-generator',
            [__CLASS__, 'render_admin_page'],
            'dashicons-edit',
            30
        );
    }

    /**
     * Enqueue CSS for admin page
     */
    public static function enqueue_assets($hook) {
        if ($hook !== 'toplevel_page_seo-post-generator') {
            return;
        }
        wp_enqueue_style(
            'seo-post-generator-admin',
            plugin_dir_url(__FILE__) . 'assets/admin.css',
            [],
            '2.5.0'
        );
    }

    /**
     * Handle form submissions
     */
    public static function handle_requests() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $actions = [
            'generate_seo_post' => 'handle_generate_post',
            'publish_post' => 'handle_publish_post',
            'generate_toc_post' => 'handle_generate_toc',
            'generate_seo_paragraph' => 'handle_generate_paragraph',
            'export_postmeta_csv' => 'handle_export_postmeta_csv',
            'import_postmeta_csv' => 'handle_import_postmeta_csv', // New action for import
        ];

        foreach ($actions as $action => $method) {
            if (isset($_POST[$action]) && check_admin_referer($action . '_nonce')) {
                call_user_func([__CLASS__, $method]);
            }
        }
    }

    /**
     * Handle SEO post generation
     */
    private static function handle_generate_post() {
        $post_title = sanitize_text_field($_POST['generate_title'] ?? '');
        if (empty($post_title)) {
            self::add_admin_notice('error', __('Post title is required.', 'seo-post-generator'));
            return;
        }

        $prompt = sprintf(
            "Generate 10 new post categories for '%s' in a single CSV row. Use highly specific, long-tail keywords aligned with %d SEO trends. Avoid generic terms and ensure high search volume.",
            $post_title,
            SEO_POST_GENERATOR_KYC_YEAR
        );

        $response = self::call_xai_api($prompt, [
            'model' => 'grok-3',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        if (is_wp_error($response)) {
            self::add_admin_notice('error', __('API request failed: ', 'seo-post-generator') . $response->get_error_message());
            self::log_sync_attempt($post_title, '', 'error', $response->get_error_message());
            return;
        }

        $categories = trim($response['choices'][0]['message']['content'] ?? '');
        $categories = str_replace('"', '', $categories);
        $content = sprintf(
            "This post explores the topic: '%s' and provides valuable insights aligned with %d SEO trends.",
            esc_html($post_title),
            SEO_POST_GENERATOR_KYC_YEAR
        );

        // Save draft to Site 2
        $_POST['post_categories'] = $categories;
        $sync_status = self::sync_post_to_site2($post_title, $content, 'draft');
        if ($sync_status !== true) {
            self::add_admin_notice('error', __('Failed to save draft to Site 2: ', 'seo-post-generator') . $sync_status);
            self::log_sync_attempt($post_title, $content, 'error', $sync_status);
            return;
        }

        self::add_admin_notice('success', __('Post draft saved to Site 2.', 'seo-post-generator'));
        self::log_sync_attempt($post_title, $content, 'success', 'Draft saved to Site 2');
    }

    /**
     * Handle post publishing
     */
    private static function handle_publish_post() {
        global $wpdb;
        $title = sanitize_text_field($_POST['post_title'] ?? '');
        $content = wp_kses_post($_POST['post_content'] ?? '');
        $categories = array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['post_categories'] ?? ''))));

        if (empty($title) || empty($content)) {
            self::add_admin_notice('error', __('Title and content are required.', 'seo-post-generator'));
            self::log_sync_attempt($title, $content, 'error', 'Title or content missing');
            return;
        }

        if (get_page_by_title($title, OBJECT, 'post')) {
            self::add_admin_notice('error', __('A post with this title already exists.', 'seo-post-generator'));
            self::log_sync_attempt($title, $content, 'error', 'Post title already exists');
            return;
        }

        // Publish to Site 1
        $post_id = wp_insert_post([
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_type' => 'post',
        ]);

        if (is_wp_error($post_id)) {
            self::add_admin_notice('error', __('Failed to publish post.', 'seo-post-generator'));
            self::log_sync_attempt($title, $content, 'error', 'Failed to publish to Site 1');
            return;
        }

        // Assign categories to Site 1
        $cat_ids = [];
        foreach ($categories as $cat) {
            $term = term_exists($cat, 'category');
            if (!$term) {
                $term = wp_insert_term($cat, 'category');
            }
            if (!is_wp_error($term)) {
                $cat_ids[] = is_array($term) ? $term['term_id'] : $term;
            }
        }
        wp_set_post_categories($post_id, $cat_ids);

        // Publish to Site 2
        $_POST['post_categories'] = implode(',', $categories);
        $sync_status = self::sync_post_to_site2($title, $content, 'publish');
        if ($sync_status !== true) {
            self::add_admin_notice('error', __('Sync to Site 2 failed: ', 'seo-post-generator') . $sync_status);
            self::log_sync_attempt($title, $content, 'error', $sync_status);
        } else {
            self::add_admin_notice('success', __('Post published and synced to Site 2.', 'seo-post-generator'));
            self::log_sync_attempt($title, $content, 'success', 'Post published to Site 2');
        }

        // Clean up Site 2 drafts
        $site2_db = self::init_site2_db();
        $site2_db->delete(
            SITE2_DB_PREFIX . 'posts',
            ['post_author' => 1, 'post_status' => 'draft'], // Adjust author ID as needed
            ['%d', '%s']
        );

        // Clear local options
        delete_option('seo_post_toc_data');
        delete_option('seo_paragraph_data');
    }

    /**
     * Handle TOC generation
     */
    private static function handle_generate_toc() {
        $post_title = sanitize_text_field($_POST['toc_title'] ?? '');
        if (empty($post_title)) {
            self::add_admin_notice('error', __('TOC title is required.', 'seo-post-generator'));
            return;
        }

        $prompt = sprintf(  
            "Generate H2-level section titles for a blog post about '%s'. Provide one heading per line, without numbering or extra text.",  
            $post_title  
        );  

        $response = self::call_xai_api($prompt, [  
            'model' => 'grok-3',  
            'temperature' => 0.5,  
            'max_tokens' => 500,  
        ]);  

        if (is_wp_error($response)) {  
            self::add_admin_notice('error', __('TOC generation failed: ', 'seo-post-generator') . $response->get_error_message());  
            self::log_sync_attempt($post_title, '', 'error', $response->get_error_message());  
            return;  
        }  

        $toc_text = trim($response['choices'][0]['message']['content'] ?? '');  
        $titles = array_filter(explode("\n", $toc_text));  

        if (empty($titles)) {  
            $titles = [  
                "Introduction to $post_title",  
                "Benefits of $post_title",  
                "How $post_title Works",  
                "Common Uses of $post_title",  
                "Future of $post_title",  
            ];  
        }  

        $toc_links = [];  
        $slugged_titles = [];  
        foreach ($titles as $title) {  
            $slug = sanitize_title($title);  
            $slugged_titles[] = $slug;  
            $toc_links[] = sprintf(  
                '<li><a href="#%s">%s</a></li>',  
                esc_attr($slug),  
                esc_html($title)  
            );  
        }  

        update_option('seo_post_toc_data', [  
            'post_title' => $post_title,  
            'toc_raw' => $toc_text,  
            'toc_links' => $toc_links,  
            'toc_titles' => $titles,  
        ]);  

        self::add_admin_notice('success', __('TOC generated successfully.', 'seo-post-generator'));  
    }  

    /**
     * Handle SEO paragraph generation
     */
    private static function handle_generate_paragraph() {
        $topic = sanitize_text_field($_POST['seo_paragraph_topic'] ?? '');
        $paragraph_count = min(max(1, intval($_POST['paragraph_count'] ?? 1)), 10);

        if (empty($topic) || strlen($topic) < 3) {
            self::add_admin_notice('error', __('Please provide a valid topic (at least 3 characters).', 'seo-post-generator'));
            self::log_sync_attempt($topic, '', 'error', 'Invalid topic');
            return;
        }

        $toc_data = get_option('seo_post_toc_data', []);
        $toc_titles = $toc_data['toc_titles'] ?? [];

        if (empty($toc_titles)) {
            $toc_titles = [
                "Introduction to $topic",
                "Benefits of $topic",
                "How $topic Works",
                "Common Uses of $topic",
                "Future of $topic",
            ];
        }

        $final_prompt = sprintf(
            "For the topic '%s', write %d SEO-optimized sections.\n" .
            "For each section:\n" .
            "- Output a <h2> with the exact section title.\n" .
            "- Then immediately after, output a <p> with an ID generated from the title (lowercase, hyphens instead of spaces).\n" .
            "- The paragraph should be around 100 words.\n" .
            "- Use natural language, no lists, no extra formatting.\n\nSection Titles:\n%s",
            $topic,
            $paragraph_count,
            implode("\n", array_map(function($title) { return "- $title"; }, array_slice($toc_titles, 0, $paragraph_count)))
        );

        $response = self::call_xai_api($final_prompt, [
            'model' => 'grok-3',
            'temperature' => 0.7,
            'max_tokens' => 3000,
        ]);

        if (is_wp_error($response)) {
            self::add_admin_notice('error', __('Paragraph generation failed: ', 'seo-post-generator') . $response->get_error_message());
            self::log_sync_attempt($topic, '', 'error', $response->get_error_message());
            return;
        }

        $output = trim($response['choices'][0]['message']['content'] ?? '');
        if (empty($output)) {
            self::add_admin_notice('error', __('No paragraph content generated.', 'seo-post-generator'));
            self::log_sync_attempt($topic, '', 'error', 'No paragraph content generated');
            return;
        }

        $sections_html = '<div class="seo-paragraphs">';
        $sections = preg_split('/(?=<h2>)/i', $output);
        foreach ($sections as $section) {
            if (preg_match('/<h2>(.*?)<\/h2>(.*)/is', $section, $matches)) {
                $heading = trim($matches[1]);
                $paragraph_text = trim($matches[2]);
                if (empty($paragraph_text)) {
                    continue;
                }
                $anchor = sanitize_title_with_dashes($heading);
                $sections_html .= sprintf(
                    '<h2>%s</h2><p id="%s">%s</p>',
                    esc_html($heading),
                    esc_attr($anchor),
                    wp_kses_post($paragraph_text)
                );
            }
        }
        $sections_html .= '</div>';

        $html_content = $sections_html;

        // Save to Site 2
        $_POST['post_categories'] = ''; // Categories may be set separately
        $sync_status = self::sync_post_to_site2($topic, $html_content, 'publish');
        if ($sync_status !== true) {
            self::add_admin_notice('error', __('Failed to sync paragraphs to Site 2: ', 'seo-post-generator') . $sync_status);
            self::log_sync_attempt($topic, $html_content, 'error', $sync_status);
            return;
        }

        // Save locally for preview
        update_option('seo_paragraph_data', [
            'heading' => $topic,
            'content' => $html_content,
        ]);

        self::add_admin_notice('success', __('Paragraphs generated and synced to Site 2.', 'seo-post-generator'));
        self::log_sync_attempt($topic, $html_content, 'success', 'Paragraphs synced to Site 2');
    }

    /**
     * Handle postmeta CSV export
     */
    private static function handle_export_postmeta_csv() {
        $site2_db = self::init_site2_db();
        if ($site2_db->last_error) {
            self::add_admin_notice('error', __('Failed to connect to Site 2 database: ', 'seo-post-generator') . $site2_db->last_error);
            return;
        }

        // Fetch posts and their metadata, including post_content
        $query = "
            SELECT p.ID, p.post_title, p.post_content, p.post_date, p.post_status, pm.meta_key, pm.meta_value
            FROM " . SITE2_DB_PREFIX . "posts p
            LEFT JOIN " . SITE2_DB_PREFIX . "postmeta pm ON p.ID = pm.post_id
            ORDER BY p.post_date DESC
        ";
        $results = $site2_db->get_results($query, ARRAY_A);

        if (empty($results)) {
            self::add_admin_notice('error', __('No post metadata found to export.', 'seo-post-generator'));
            return;
        }

        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="postmeta_export_' . date('Y-m-d_H-i-s') . '.csv"');

        // Open output stream
        $output = fopen('php://output', 'w');

        // Write CSV headers
        fputcsv($output, ['ID', 'post_title', 'post_content', 'post_date', 'post_status', 'meta_key', 'meta_value']);

        // Write data rows
        foreach ($results as $row) {
            fputcsv($output, [
                $row['ID'],
                $row['post_title'],
                $row['post_content'],
                $row['post_date'],
                $row['post_status'],
                $row['meta_key'],
                $row['meta_value']
            ]);
        }

        // Close output stream and exit
        fclose($output);
        exit;
    }

    /**
     * Handle postmeta CSV import
     */
    private static function handle_import_postmeta_csv() {
        $site2_db = self::init_site2_db();
        if ($site2_db->last_error) {
            self::add_admin_notice('error', __('Failed to connect to Site 2 database: ', 'seo-post-generator') . $site2_db->last_error);
            return;
        }

        if (empty($_FILES['import_file']['tmp_name'])) {
            self::add_admin_notice('error', __('Please upload a CSV file.', 'seo-post-generator'));
            return;
        }

        $file = $_FILES['import_file']['tmp_name'];
        if (($handle = fopen($file, 'r')) === false) {
            self::add_admin_notice('error', __('Failed to open the uploaded CSV file.', 'seo-post-generator'));
            return;
        }

        // Read and skip the header row
        $header = fgetcsv($handle);
        if ($header === false || !in_array('ID', $header) || !in_array('meta_key', $header) || !in_array('meta_value', $header)) {
            fclose($handle);
            self::add_admin_notice('error', __('Invalid CSV format. Ensure columns include ID, meta_key, and meta_value.', 'seo-post-generator'));
            return;
        }

        $imported = 0;
        $skipped = 0;
        while (($data = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $data);

            // Ensure required fields are present
            if (empty($row['ID']) || $row['ID'] === '' || !is_numeric($row['ID'])) {
                $skipped++;
                continue;
            }

            $post_id = intval($row['ID']);
            $meta_key = sanitize_text_field($row['meta_key'] ?? '');
            $meta_value = sanitize_text_field($row['meta_value'] ?? '');

            if (empty($meta_key)) {
                $skipped++;
                continue;
            }

            // Check if the post exists in nhyBTK_posts
            $post_exists = $site2_db->get_var(
                $site2_db->prepare("SELECT ID FROM " . SITE2_DB_PREFIX . "posts WHERE ID = %d", $post_id)
            );
            if (!$post_exists) {
                $skipped++;
                continue;
            }

            // Check if the meta entry already exists
            $existing_meta = $site2_db->get_row(
                $site2_db->prepare(
                    "SELECT meta_id FROM " . SITE2_DB_PREFIX . "postmeta WHERE post_id = %d AND meta_key = %s",
                    $post_id,
                    $meta_key
                )
            );

            if ($existing_meta) {
                // Update existing meta
                $result = $site2_db->update(
                    SITE2_DB_PREFIX . 'postmeta',
                    ['meta_value' => $meta_value],
                    ['meta_id' => $existing_meta->meta_id],
                    ['%s'],
                    ['%d']
                );
            } else {
                // Insert new meta
                $result = $site2_db->insert(
                    SITE2_DB_PREFIX . 'postmeta',
                    [
                        'post_id' => $post_id,
                        'meta_key' => $meta_key,
                        'meta_value' => $meta_value
                    ],
                    ['%d', '%s', '%s']
                );
            }

            if ($result !== false) {
                $imported++;
            } else {
                $skipped++;
                error_log('SEO Post Generator: Failed to import meta for post_id ' . $post_id . ': ' . $site2_db->last_error);
            }
        }

        fclose($handle);
        self::add_admin_notice('success', sprintf(
            __('Import complete. %d entries imported, %d skipped.', 'seo-post-generator'),
            $imported,
            $skipped
        ));
    }

    /**
     * Call xAI API with caching
     */
    private static function call_xai_api($prompt, $params = []) {
        $transient_key = SEO_POST_GENERATOR_TRANSIENT_PREFIX . md5($prompt . serialize($params));
        $cached = get_transient($transient_key);
        if ($cached !== false) {
            return $cached;
        }

        $payload = array_merge([
            'model' => 'grok-3',
            'messages' => [
                ['role' => 'system', 'content' => 'You are a helpful AI assistant created by xAI.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'stream' => false,
            'timeout' => 30,
        ], $params);

        $response = wp_remote_post(SEO_POST_GENERATOR_API_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . SEO_POST_GENERATOR_API_KEY,
            ],
            'body' => json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!isset($body['choices'][0]['message']['content'])) {
            return new WP_Error('api_error', __('Invalid API response.', 'seo-post-generator'));
        }

        set_transient($transient_key, $body, HOUR_IN_SECONDS);
        return $body;
    }

    /**
     * Sync post to Site 2 by inserting into nhyBTK_posts
     */
    private static function sync_post_to_site2($title, $content, $post_status = 'publish') {
        $site2_db = self::init_site2_db();
        if ($site2_db->last_error) {
            return 'Failed to connect to Site 2 database: ' . $site2_db->last_error;
        }

        $title = sanitize_text_field($title);
        $content = wp_kses_post($content);
        $post_name = sanitize_title($title);
        $post_date = current_time('mysql');
        $post_date_gmt = current_time('mysql', 1);
        $author_id = 1; // Adjust to match Site 2's admin user ID
        $guid = 'https://one.roomrentonline.com/?p=';

        $result = $site2_db->insert(
            SITE2_DB_PREFIX . 'posts',
            [
                'post_author' => $author_id,
                'post_date' => $post_date,
                'post_date_gmt' => $post_date_gmt,
                'post_content' => $content,
                'post_title' => $title,
                'post_excerpt' => '',
                'post_status' => $post_status,
                'comment_status' => 'open',
                'ping_status' => 'open',
                'post_password' => '',
                'post_name' => $post_name,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $post_date,
                'post_modified_gmt' => $post_date_gmt,
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => $guid,
                'menu_order' => 0,
                'post_type' => 'post',
                'post_mime_type' => '',
                'comment_count' => 0,
            ],
            [
                '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%d'
            ]
        );

        if ($result === false) {
            $error = sprintf('Database error: %s', $site2_db->last_error);
            error_log('SEO Post Generator: ' . $error);
            return $error;
        }

        $post_id = $site2_db->insert_id;
        $guid = "https://one.roomrentonline.com/?p={$post_id}";
        $site2_db->update(
            SITE2_DB_PREFIX . 'posts',
            ['guid' => $guid],
            ['ID' => $post_id],
            ['%s'],
            ['%d']
        );

        $categories = isset($_POST['post_categories']) ? array_filter(array_map('trim', explode(',', sanitize_text_field($_POST['post_categories'])))) : [];
        if (!empty($categories)) {
            self::assign_categories_to_post($site2_db, $post_id, $categories);
        }

        return true;
    }

    /**
     * Assign categories to a post in Site 2
     */
    private static function assign_categories_to_post($site2_db, $post_id, $categories) {
        foreach ($categories as $category) {
            $term = $site2_db->get_row(
                $site2_db->prepare(
                    "SELECT t.term_id, tt.term_taxonomy_id FROM " . SITE2_DB_PREFIX . "terms t 
                     JOIN " . SITE2_DB_PREFIX . "term_taxonomy tt ON t.term_id = tt.term_id 
                     WHERE t.name = %s AND tt.taxonomy = 'category'",
                    $category
                )
            );

            if (!$term) {
                $site2_db->insert(
                    SITE2_DB_PREFIX . 'terms',
                    ['name' => $category, 'slug' => sanitize_title($category), 'term_group' => 0],
                    ['%s', '%s', '%d']
                );
                $term_id = $site2_db->insert_id;

                $site2_db->insert(
                    SITE2_DB_PREFIX . 'term_taxonomy',
                    ['term_id' => $term_id, 'taxonomy' => 'category', 'description' => '', 'parent' => 0, 'count' => 0],
                    ['%d', '%s', '%s', '%d', '%d']
                );
                $term_taxonomy_id = $site2_db->insert_id;
            } else {
                $term_id = $term->term_id;
                $term_taxonomy_id = $term->term_taxonomy_id;
            }

            $site2_db->insert(
                SITE2_DB_PREFIX . 'term_relationships',
                ['object_id' => $post_id, 'term_taxonomy_id' => $term_taxonomy_id, 'term_order' => 0],
                ['%d', '%d', '%d']
            );

            $site2_db->query(
                $site2_db->prepare(
                    "UPDATE " . SITE2_DB_PREFIX . "term_taxonomy SET count = (
                        SELECT COUNT(*) FROM " . SITE2_DB_PREFIX . "term_relationships WHERE term_taxonomy_id = %d
                    ) WHERE term_taxonomy_id = %d",
                    $term_taxonomy_id,
                    $term_taxonomy_id
                )
            );
        }
    }

    /**
     * Log sync attempts to wp_seo_sync_logs
     */
    private static function log_sync_attempt($post_title, $content, $status, $message) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'seo_sync_logs',
            [
                'post_title' => sanitize_text_field($post_title),
                'content' => wp_kses_post($content),
                'status' => $status,
                'message' => sanitize_text_field($message),
                'user_id' => get_current_user_id(),
                'created_at' => current_time('mysql'),
            ],
            ['%s', '%s', '%s', '%s', '%d', '%s']
        );
    }

    /**
     * Add admin notice
     */
    private static function add_admin_notice($type, $message) {
        add_action('admin_notices', function() use ($type, $message) {
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
        });
    }

    /**
     * Render admin page
     */
    public static function render_admin_page() {
        $site2_db = self::init_site2_db();
        $draft = $site2_db->get_row(
            $site2_db->prepare(
                "SELECT post_title AS title, post_content AS content, '' AS categories 
                 FROM " . SITE2_DB_PREFIX . "posts 
                 WHERE post_author = %d AND post_status = 'draft' 
                 ORDER BY post_date DESC LIMIT 1",
                1 // Adjust to match Site 2's admin user ID
            ),
            ARRAY_A
        );
        $draft = $draft ?: ['title' => '', 'content' => '', 'categories' => ''];

        // Fetch all posts from Site 2
        $saved_posts = $site2_db->get_results(
            "SELECT * FROM " . SITE2_DB_PREFIX . "posts ORDER BY post_date DESC",
            ARRAY_A
        );

        $toc = get_option('seo_post_toc_data', ['post_title' => '', 'toc_raw' => '', 'toc_titles' => [], 'toc_links' => []]);
        $seo_paragraph_data = get_option('seo_paragraph_data', ['heading' => '', 'content' => '']);
        ?>
        <div class="wrap seo-post-generator">
            <h1><?php esc_html_e('SEO Post Generator', 'seo-post-generator'); ?></h1>

            <!-- Post Preview & Submit -->
            <div class="seo-section">
                <h2><?php esc_html_e('Post Preview & Submit', 'seo-post-generator'); ?></h2>
                <form method="POST">
                    <?php wp_nonce_field('publish_post_nonce'); ?>
                    <p>
                        <strong><?php esc_html_e('Title:', 'seo-post-generator'); ?></strong><br>
                        <input type="text" name="post_title" class="widefat" value="<?php echo esc_attr($draft['title']); ?>" required>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Content:', 'seo-post-generator'); ?></strong><br>
                        <textarea name="post_content" class="widefat" rows="10"><?php
                            echo esc_textarea(implode("\n", (array)$toc['toc_links']) . "\n\n" . ($seo_paragraph_data['content'] ?: $draft['content']));
                        ?></textarea>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Categories:', 'seo-post-generator'); ?></strong><br>
                        <input type="text" name="post_categories" class="widefat" value="<?php echo esc_attr($draft['categories']); ?>" required>
                    </p>
                    <input type="submit" name="publish_post" class="button button-primary" value="<?php esc_attr_e('Post to WordPress', 'seo-post-generator'); ?>">
                </form>
            </div>

            <!-- Generate Post -->
            <div class="seo-section">
                <h2><?php esc_html_e('Generate Post', 'seo-post-generator'); ?></h2>
                <form method="POST">
                    <?php wp_nonce_field('generate_seo_post_nonce'); ?>
                    <p>
                        <strong><?php esc_html_e('Enter Post Title:', 'seo-post-generator'); ?></strong><br>
                        <input type="text" name="generate_title" class="widefat" required>
                    </p>
                    <input type="submit" name="generate_seo_post" class="button button-secondary" value="<?php esc_attr_e('Generate', 'seo-post-generator'); ?>">
                </form>
            </div>

            <!-- TOC Generator -->
            <div class="seo-section">
                <h2><?php esc_html_e('Table of Contents', 'seo-post-generator'); ?></h2>
                <form method="POST">
                    <?php wp_nonce_field('generate_toc_post_nonce'); ?>
                    <p>
                        <strong><?php esc_html_e('Content:', 'seo-post-generator'); ?></strong><br>
                        <textarea name="post_content" class="widefat" rows="5"><?php echo esc_textarea($toc['toc_raw']); ?></textarea>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Enter TOC Title:', 'seo-post-generator'); ?></strong><br>
                        <input type="text" name="toc_title" class="widefat" value="<?php echo esc_attr($toc['post_title']); ?>" required>
                    </p>
                    <input type="submit" name="generate_toc_post" class="button button-secondary" value="<?php esc_attr_e('Generate Table of Contents', 'seo-post-generator'); ?>">
                </form>
            </div>

            <!-- SEO Paragraph Generator -->
            <div class="seo-section">
                <h2><?php esc_html_e('SEO Paragraph', 'seo-post-generator'); ?></h2>
                <form method="POST">
                    <?php wp_nonce_field('generate_seo_paragraph_nonce'); ?>
                    <p>
                        <strong><?php esc_html_e('Content:', 'seo-post-generator'); ?></strong><br>
                        <textarea name="post_content" class="widefat" rows="5"><?php echo esc_textarea($seo_paragraph_data['content'] ?? ''); ?></textarea>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Enter SEO Topic:', 'seo-post-generator'); ?></strong><br>
                        <input type="text" name="seo_paragraph_topic" class="widefat" value="<?php echo esc_attr($seo_paragraph_data['heading'] ?? ''); ?>" required>
                    </p>
                    <p>
                        <strong><?php esc_html_e('Number of Paragraphs:', 'seo-post-generator'); ?></strong><br>
                        <input type="number" name="paragraph_count" min="1" max="10" value="1" class="widefat" required>
                    </p>
                    <input type="submit" name="generate_seo_paragraph" class="button button-secondary" value="<?php esc_attr_e('Generate', 'seo-post-generator'); ?>">
                </form>
            </div>

            <!-- Display All Saved Posts and Postmeta Import/Export -->
            <div class="seo-section">
                <h2><?php esc_html_e('Postmeta Import/Export Site2', 'seo-post-generator'); ?></h2>
                <!-- Export Form -->
                <form method="POST">
                    <?php wp_nonce_field('export_postmeta_csv_nonce'); ?>
                    <input type="submit" name="export_postmeta_csv" class="button button-secondary" value="<?php esc_attr_e('Download Postmeta as CSV', 'seo-post-generator'); ?>">
                </form>
                <br>
                <!-- Import Form -->
                <form method="POST" enctype="multipart/form-data">
                    <?php wp_nonce_field('import_postmeta_csv_nonce'); ?>
                    <p>
                        <strong><?php esc_html_e('Upload Postmeta CSV:', 'seo-post-generator'); ?></strong><br>
                        <input type="file" name="import_file" accept=".csv" required>
                    </p>
                    <input type="submit" name="import_postmeta_csv" class="button button-secondary" value="<?php esc_attr_e('Import Postmeta from CSV', 'seo-post-generator'); ?>">
                </form>
                <br>
               
            </div>
        </div>
        <?php
    }
}

// Initialize plugin
SEOPostGenerator::init();
