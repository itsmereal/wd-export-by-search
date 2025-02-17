<?php
/**
 * Plugin Name: WD Export by Search
 * Description: Searches for a specified string in posts, pages, and custom post types, then exports matching URLs as a CSV.
 * Version: 1.0
 * Author: WolfDevs
 * Author URI: https://wolfdevs.com
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit; // Prevent direct access
}

// Enqueue admin scripts and styles
function wdes_enqueue_admin_assets($hook) {
    if ($hook !== 'tools_page_export-custom-search') {
        return;
    }

    $version = '1.0';

    wp_enqueue_script(
        'wdes-admin-script',
        plugin_dir_url(__FILE__) . 'admin.js',
        ['wp-element', 'wp-components', 'wp-api-fetch'],
        $version,
        true
    );

    wp_localize_script('wdes-admin-script', 'wdes_admin', [
        'nonce' => wp_create_nonce('wdes_export_nonce_action'),
        'ajax_url' => admin_url('admin-ajax.php'),
    ]);

    wp_enqueue_style(
        'wdes-admin-style',
        plugin_dir_url(__FILE__) . 'admin.css',
        [],
        $version
    );
}
add_action('admin_enqueue_scripts', 'wdes_enqueue_admin_assets');

// Add menu item for the plugin
function wdes_admin_menu() {
    add_submenu_page(
        'tools.php',
        'Export by Search',
        'Export by Search',
        'manage_options',
        'export-custom-search',
        'wdes_render_admin_page'
    );
}
add_action('admin_menu', 'wdes_admin_menu');

// Add settings link on plugin page
function wdes_add_plugin_links($links) {
    $settings_link = '<a href="' . admin_url('tools.php?page=export-custom-search') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wdes_add_plugin_links');

// Render the React container
function wdes_render_admin_page() {
    echo '<div id="wdes-admin-app"></div>';
}

// Add this function near the top of the file
function wdes_modify_meta_query($query) {
    if (isset($query->query_vars['wdes_meta_search'])) {
        $search_string = $query->query_vars['wdes_meta_search'];

        // Add our custom filter
        add_filter('posts_where', function($where) use ($search_string, $query) {
            global $wpdb;

            // Get post types from query
            $post_types = (array)$query->get('post_type');
            if (empty($post_types)) return $where;

            $like = '%' . $wpdb->esc_like($search_string) . '%';
            $underscore_like = '\_%';

            // Build post type conditions
            $post_types_placeholders = implode(',', array_fill(0, count($post_types), '%s'));
            $post_type_clause = $wpdb->prepare("AND {$wpdb->posts}.post_type IN ($post_types_placeholders) ", $post_types);

            // Add the meta search condition while maintaining post type restriction
            $meta_where = $wpdb->prepare(
                " OR ({$wpdb->posts}.post_type IN ($post_types_placeholders)
                    AND EXISTS (
                        SELECT 1 FROM {$wpdb->postmeta}
                        WHERE {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
                        AND (
                            {$wpdb->postmeta}.meta_key IN ('_elementor_data', 'content', '_wp_page_template', 'slide_template')
                            OR {$wpdb->postmeta}.meta_key NOT LIKE %s
                        )
                        AND {$wpdb->postmeta}.meta_value LIKE %s
                    )
                )",
                array_merge($post_types, [$underscore_like, $like])
            );

            // Add our conditions to the existing WHERE clause
            return preg_replace(
                '/(?=\s*(?:ORDER|LIMIT|GROUP|$))/i',
                $meta_where,
                $where
            );
        });

        // Remove the filter after the query is done
        add_filter('posts_results', function($posts) {
            remove_all_filters('posts_where');
            return $posts;
        });
    }
    return $query;
}
add_action('pre_get_posts', 'wdes_modify_meta_query');

// Handle CSV export request
function wdes_export_posts_by_search() {
    check_admin_referer('wdes_export_nonce_action', 'wdes_export_nonce');

    try {
        global $wpdb;

        // Validate and sanitize inputs with wp_unslash first
        $search_string = '';
        $post_types = [];
        $file_name = 'exported_posts.csv';

        if (isset($_POST['search_string'])) {
            $search_string = sanitize_text_field(wp_unslash($_POST['search_string']));
        }
        if (isset($_POST['post_types'])) {
            // Sanitize the entire post_types string first
            $post_types_raw = sanitize_text_field(wp_unslash($_POST['post_types']));
            $post_types = array_map('sanitize_text_field', explode(',', $post_types_raw));
        }
        if (isset($_POST['file_name'])) {
            $file_name = sanitize_text_field(wp_unslash($_POST['file_name']));
        }

        if (empty($search_string) || empty($post_types)) {
            throw new Exception('Search string and post types are required.');
        }

        // Generate cache key
        $cache_key = 'wdes_search_' . md5($search_string . implode(',', $post_types));

        // Clear the cache for this search
        wp_cache_delete($cache_key);

        // Single query for both content and meta
        $args = array(
            'post_type' => $post_types,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            's' => $search_string,
            'suppress_filters' => false,
            'wdes_meta_search' => $search_string,
            'cache_results' => false // Disable WP_Query caching
        );

        $query = new WP_Query($args);
        $post_ids = $query->posts;

        // Cache the results for 1 hour
        wp_cache_set($cache_key, $post_ids, '', HOUR_IN_SECONDS);

        if (empty($post_ids)) {
            throw new Exception("No posts found containing '{$search_string}'.");
        }

        // Ensure filename has .csv extension
        if (!str_ends_with($file_name, '.csv')) {
            $file_name .= '.csv';
        }

        // Set up WordPress filesystem
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        global $wp_filesystem;
        WP_Filesystem();

        if (!$wp_filesystem) {
            throw new Exception('WordPress filesystem not initialized.');
        }

        // Clear any previous output
        if (ob_get_length()) {
            ob_clean();
        }

        // Disable any further output buffering
        while (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for CSV download
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($file_name) . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Use WordPress filesystem for temporary file
        $temp_file = wp_tempnam('wdes_export_');
        if (!$temp_file) {
            throw new Exception('Failed to create temporary file');
        }

        // Write to temporary file using WP_Filesystem
        $csv_content = "\"Post Title\",\"Post Type\",\"Post URL\"\n";

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if ($post) {
                $post_type_obj = get_post_type_object($post->post_type);
                $post_type_label = $post_type_obj ? $post_type_obj->labels->singular_name : $post->post_type;

                $csv_line = sprintf(
                    "%s,%s,%s\n",
                    wdes_esc_csv($post->post_title),
                    wdes_esc_csv($post_type_label),
                    wdes_esc_csv(get_permalink($post_id))
                );
                $csv_content .= $csv_line;
            }
        }

        if (!$wp_filesystem->put_contents($temp_file, $csv_content)) {
            throw new Exception('Failed to write to temporary file');
        }

        // Output file contents and delete using WP_Filesystem
        $file_contents = $wp_filesystem->get_contents($temp_file);
        if (false === $file_contents) {
            throw new Exception('Failed to read temporary file');
        }

        // Clean up before output
        $wp_filesystem->delete($temp_file);

        // Output the CSV content directly without HTML escaping
        echo $file_contents;
        exit;

    } catch (Exception $e) {
        wp_send_json_error(['message' => esc_html($e->getMessage())]);
        return;
    }
}

function wdes_esc_csv($value) {
    // Remove any HTML entities that might be in the content
    $value = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    // First escape any quotes in the value
    $escaped = str_replace('"', '""', $value);
    // Then wrap the entire value in quotes
    return '"' . $escaped . '"';
}

add_action('admin_post_wdes_export_csv', 'wdes_export_posts_by_search');

// Handle AJAX request for post types
/**
 * Get all public post types
 *
 * @global wpdb $wpdb WordPress database abstraction object.
 * @return void
 */
function wdes_get_post_types() {
    global $wpdb;

    // Cache key for post types
    $cache_key = 'wdes_post_types';
    $response = wp_cache_get($cache_key);

    if (false === $response) {
        // Direct database query used for performance with caching
        $types = get_post_types(['public' => true], 'objects');
        $response = [];
        foreach ($types as $type) {
            $response[] = ['value' => $type->name, 'label' => $type->label];
        }
        // Cache the post types for 12 hours since they rarely change
        wp_cache_set($cache_key, $response, '', 12 * HOUR_IN_SECONDS);
    }

    wp_send_json($response);
}
add_action('wp_ajax_wdes_get_post_types', 'wdes_get_post_types');
