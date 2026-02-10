<?php
/**
 * Plugin Name: Open Sitemap Generator
 * Plugin URI: https://github.com/quakef4/Open-Sitemap-Generator
 * Description: Genera sitemap XML dinamiche con paginazione automatica, IndexNow a coda temporizzata, Google Rich Results per WooCommerce e notifica Google/Bing/Yandex. Ottimizzato per WooCommerce con decine di migliaia di prodotti.
 * Version: 1.4.0
 * Author: quakef4
 * Author URI: https://github.com/quakef4
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: open-sitemap-generator
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Tested up to: 6.7
 */

if (!defined('ABSPATH')) {
    exit;
}

define('OSG_VERSION', '1.4.0');
define('OSG_PATH', plugin_dir_path(__FILE__));
define('OSG_URL', plugin_dir_url(__FILE__));
define('OSG_BASENAME', plugin_basename(__FILE__));

// Max URL per file sitemap (standard: max 50.000, default 10.000 per performance)
define('OSG_MAX_URLS_PER_SITEMAP', 10000);

/**
 * Classe principale del plugin
 */
class Open_Sitemap_Generator {
    
    private static $instance = null;
    private $options;
    
    /** @var wpdb */
    private $db;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->db = $wpdb;
        $this->options = get_option('osg_options', $this->get_default_options());
        
        // Attivazione/Disattivazione
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // Rewrite rules per sitemap (con paginazione)
        add_action('init', array($this, 'add_rewrite_rules'));
        add_filter('query_vars', array($this, 'add_query_vars'));
        add_action('template_redirect', array($this, 'serve_sitemap'));
        
        // Auto-rigenerazione e notifica Google
        add_action('save_post', array($this, 'on_content_change'), 10, 2);
        add_action('delete_post', array($this, 'on_content_change'));
        add_action('create_term', array($this, 'on_content_change'));
        add_action('edit_term', array($this, 'on_content_change'));
        add_action('delete_term', array($this, 'on_content_change'));
        
        // Cron
        add_action('osg_notify_google', array($this, 'ping_google'));
        
        // Robots.txt
        add_filter('robots_txt', array($this, 'add_sitemap_to_robots'), 10, 2);
        
        // AJAX
        add_action('wp_ajax_osg_generate_sitemap', array($this, 'ajax_generate_sitemap'));
        add_action('wp_ajax_osg_ping_google', array($this, 'ajax_ping_google'));
        
        // Link impostazioni
        add_filter('plugin_action_links_' . OSG_BASENAME, array($this, 'settings_link'));
    }
    
    /**
     * Opzioni di default
     */
    private function get_default_options() {
        return array(
            'include_pages'              => true,
            'include_posts'              => true,
            'include_products'           => true,
            'include_categories'         => true,
            'include_product_categories' => true,
            'include_tags'               => false,
            'exclude_urls'               => "/cart/\n/checkout/\n/my-account/\n/wishlist/\n/compare/",
            'exclude_ids'                => '',
            'priority_home'              => '1.0',
            'priority_pages'             => '0.8',
            'priority_posts'             => '0.6',
            'priority_products'          => '0.7',
            'priority_categories'        => '0.7',
            'auto_regenerate'            => true,
            'ping_google_on_change'      => true,
            'max_urls_per_sitemap'       => OSG_MAX_URLS_PER_SITEMAP,
            'last_generated'             => '',
            'last_google_ping'           => '',
            'last_google_ping_status'    => '',
        );
    }
    
    // =============================================================
    //  ATTIVAZIONE / DISATTIVAZIONE
    // =============================================================
    
    public function activate() {
        $this->add_rewrite_rules();
        flush_rewrite_rules();
        
        if (!get_option('osg_options')) {
            update_option('osg_options', $this->get_default_options());
        }
        
        $this->options['last_generated'] = current_time('mysql');
        update_option('osg_options', $this->options);
    }
    
    public function deactivate() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook('osg_notify_google');
        wp_clear_scheduled_hook('osg_indexnow_process_queue');
    }
    
    // =============================================================
    //  REWRITE RULES CON PAGINAZIONE
    // =============================================================
    
    public function add_rewrite_rules() {
        // Sitemap index
        add_rewrite_rule(
            '^sitemap\.xml$',
            'index.php?osg_sitemap=index',
            'top'
        );
        
        // Sitemap paginate: sitemap-products-1.xml, sitemap-products-2.xml, ecc.
        add_rewrite_rule(
            '^sitemap-(pages|posts|products|categories)-(\d+)\.xml$',
            'index.php?osg_sitemap=$matches[1]&osg_sitemap_page=$matches[2]',
            'top'
        );
        
        // Retrocompatibilità: sitemap-products.xml → pagina 1
        add_rewrite_rule(
            '^sitemap-(pages|posts|products|categories)\.xml$',
            'index.php?osg_sitemap=$matches[1]&osg_sitemap_page=1',
            'top'
        );
    }
    
    public function add_query_vars($vars) {
        $vars[] = 'osg_sitemap';
        $vars[] = 'osg_sitemap_page';
        return $vars;
    }
    
    // =============================================================
    //  SERVE SITEMAP
    // =============================================================
    
    public function serve_sitemap() {
        $type = get_query_var('osg_sitemap');
        if (!$type) return;
        
        $page = max(1, intval(get_query_var('osg_sitemap_page', 1)));
        
        header('Content-Type: application/xml; charset=UTF-8');
        header('X-Robots-Tag: noindex, follow');
        header('Cache-Control: public, max-age=3600');
        
        switch ($type) {
            case 'index':
                echo $this->build_sitemap_index();
                break;
            case 'pages':
                echo $this->build_sitemap_type('page', $page, $this->options['priority_pages']);
                break;
            case 'posts':
                echo $this->build_sitemap_type('post', $page, $this->options['priority_posts']);
                break;
            case 'products':
                echo $this->build_sitemap_type('product', $page, $this->options['priority_products']);
                break;
            case 'categories':
                echo $this->build_sitemap_categories();
                break;
            default:
                status_header(404);
                echo '<?xml version="1.0"?><e>Sitemap not found</e>';
        }
        exit;
    }
    
    // =============================================================
    //  SITEMAP INDEX (con paginazione dinamica)
    // =============================================================
    
    private function build_sitemap_index() {
        $xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        
        $home    = home_url('/');
        $max_url = intval($this->options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP);
        
        if (!empty($this->options['include_pages'])) {
            $count = $this->count_published('page');
            $pages = max(1, ceil($count / $max_url));
            for ($i = 1; $i <= $pages; $i++) {
                $lastmod = $this->get_last_modified('page', $i, $max_url);
                $xml .= $this->sitemap_entry("{$home}sitemap-pages-{$i}.xml", $lastmod);
            }
        }
        
        if (!empty($this->options['include_posts'])) {
            $count = $this->count_published('post');
            if ($count > 0) {
                $pages = max(1, ceil($count / $max_url));
                for ($i = 1; $i <= $pages; $i++) {
                    $lastmod = $this->get_last_modified('post', $i, $max_url);
                    $xml .= $this->sitemap_entry("{$home}sitemap-posts-{$i}.xml", $lastmod);
                }
            }
        }
        
        if (!empty($this->options['include_products']) && $this->is_woocommerce()) {
            $count = $this->count_published('product');
            if ($count > 0) {
                $pages = max(1, ceil($count / $max_url));
                for ($i = 1; $i <= $pages; $i++) {
                    $lastmod = $this->get_last_modified('product', $i, $max_url);
                    $xml .= $this->sitemap_entry("{$home}sitemap-products-{$i}.xml", $lastmod);
                }
            }
        }
        
        if (!empty($this->options['include_categories']) || !empty($this->options['include_product_categories'])) {
            $xml .= $this->sitemap_entry("{$home}sitemap-categories-1.xml", date('Y-m-d'));
        }
        
        $xml .= '</sitemapindex>';
        return $xml;
    }
    
    private function sitemap_entry($loc, $lastmod) {
        return "  <sitemap>\n" .
               "    <loc>" . esc_url($loc) . "</loc>\n" .
               "    <lastmod>{$lastmod}</lastmod>\n" .
               "  </sitemap>\n";
    }
    
    // =============================================================
    //  SITEMAP PER TIPO (query DB dirette con paginazione)
    // =============================================================
    
    private function build_sitemap_type($post_type, $page, $priority) {
        $xml = $this->xml_header();
        
        if ($post_type === 'page' && $page === 1) {
            $xml .= $this->url_entry(home_url('/'), date('Y-m-d'), 'daily', $this->options['priority_home']);
        }
        
        if ($post_type === 'product' && !$this->is_woocommerce()) {
            $xml .= '</urlset>';
            return $xml;
        }
        
        $max_url = intval($this->options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP);
        $offset  = ($page - 1) * $max_url;
        
        $exclude_ids = $this->get_excluded_ids($post_type);
        $exclude_clause = '';
        if (!empty($exclude_ids)) {
            $placeholders = implode(',', array_fill(0, count($exclude_ids), '%d'));
            $exclude_clause = $this->db->prepare(" AND p.ID NOT IN ($placeholders)", $exclude_ids);
        }
        
        $sql = $this->db->prepare(
            "SELECT p.ID, p.post_modified
             FROM {$this->db->posts} p
             WHERE p.post_type = %s
               AND p.post_status = 'publish'
               {$exclude_clause}
             ORDER BY p.post_modified DESC
             LIMIT %d OFFSET %d",
            $post_type, $max_url, $offset
        );
        
        $rows = $this->db->get_results($sql);
        
        if ($rows) {
            $hidden_ids = ($post_type === 'product') ? $this->get_hidden_product_ids() : array();
            
            foreach ($rows as $row) {
                if (in_array((int) $row->ID, $hidden_ids)) continue;
                
                $url = get_permalink($row->ID);
                if (!$url || $this->is_excluded_url($url)) continue;
                
                $lastmod    = date('Y-m-d', strtotime($row->post_modified));
                $changefreq = $this->calc_changefreq($row->post_modified);
                
                $xml .= $this->url_entry($url, $lastmod, $changefreq, $priority);
            }
        }
        
        $xml .= '</urlset>';
        return $xml;
    }
    
    // =============================================================
    //  SITEMAP CATEGORIE
    // =============================================================
    
    private function build_sitemap_categories() {
        $xml = $this->xml_header();
        $priority = $this->options['priority_categories'];
        
        if (!empty($this->options['include_categories'])) {
            $cats = get_terms(array('taxonomy' => 'category', 'hide_empty' => true));
            if (!is_wp_error($cats)) {
                foreach ($cats as $cat) {
                    $url = get_term_link($cat);
                    if (!is_wp_error($url) && !$this->is_excluded_url($url)) {
                        $xml .= $this->url_entry($url, date('Y-m-d'), 'weekly', $priority);
                    }
                }
            }
        }
        
        if (!empty($this->options['include_product_categories']) && $this->is_woocommerce()) {
            $product_cats = get_terms(array('taxonomy' => 'product_cat', 'hide_empty' => true));
            if (!is_wp_error($product_cats)) {
                foreach ($product_cats as $cat) {
                    $url = get_term_link($cat);
                    if (!is_wp_error($url) && !$this->is_excluded_url($url)) {
                        $xml .= $this->url_entry($url, date('Y-m-d'), 'daily', '0.8');
                    }
                }
            }
        }
        
        if (!empty($this->options['include_tags'])) {
            $tags = get_terms(array('taxonomy' => 'post_tag', 'hide_empty' => true));
            if (!is_wp_error($tags)) {
                foreach ($tags as $tag) {
                    $url = get_term_link($tag);
                    if (!is_wp_error($url) && !$this->is_excluded_url($url)) {
                        $xml .= $this->url_entry($url, date('Y-m-d'), 'monthly', '0.4');
                    }
                }
            }
        }
        
        $xml .= '</urlset>';
        return $xml;
    }
    
    // =============================================================
    //  HELPER XML
    // =============================================================
    
    private function xml_header() {
        return '<?xml version="1.0" encoding="UTF-8"?>' . "\n" .
               '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    }
    
    private function url_entry($loc, $lastmod, $changefreq, $priority) {
        return "  <url>\n" .
               "    <loc>" . esc_url($loc) . "</loc>\n" .
               "    <lastmod>{$lastmod}</lastmod>\n" .
               "    <changefreq>{$changefreq}</changefreq>\n" .
               "    <priority>{$priority}</priority>\n" .
               "  </url>\n";
    }
    
    // =============================================================
    //  QUERY DB OTTIMIZZATE
    // =============================================================
    
    private function count_published($post_type) {
        return (int) $this->db->get_var($this->db->prepare(
            "SELECT COUNT(*) FROM {$this->db->posts} WHERE post_type = %s AND post_status = 'publish'",
            $post_type
        ));
    }
    
    private function count_terms($taxonomy) {
        return (int) wp_count_terms(array('taxonomy' => $taxonomy, 'hide_empty' => true));
    }
    
    private function get_last_modified($post_type, $page, $per_page) {
        $offset = ($page - 1) * $per_page;
        $date = $this->db->get_var($this->db->prepare(
            "SELECT MAX(post_modified) FROM (
                SELECT post_modified FROM {$this->db->posts}
                WHERE post_type = %s AND post_status = 'publish'
                ORDER BY post_modified DESC LIMIT %d OFFSET %d
             ) sub",
            $post_type, $per_page, $offset
        ));
        return $date ? date('Y-m-d', strtotime($date)) : date('Y-m-d');
    }
    
    private function get_hidden_product_ids() {
        if (!$this->is_woocommerce()) return array();
        $term = get_term_by('slug', 'exclude-from-catalog', 'product_visibility');
        if (!$term) return array();
        $ids = $this->db->get_col($this->db->prepare(
            "SELECT object_id FROM {$this->db->term_relationships} WHERE term_taxonomy_id = %d",
            $term->term_taxonomy_id
        ));
        return array_map('intval', $ids);
    }
    
    // =============================================================
    //  ESCLUSIONI
    // =============================================================
    
    private function get_excluded_ids($post_type) {
        $ids = array();
        if ($post_type === 'page' && $this->is_woocommerce()) {
            foreach (array('cart', 'checkout', 'myaccount') as $slug) {
                $page_id = wc_get_page_id($slug);
                if ($page_id > 0) $ids[] = $page_id;
            }
        }
        if (!empty($this->options['exclude_ids'])) {
            $manual = array_map('intval', array_filter(array_map('trim', explode(',', $this->options['exclude_ids']))));
            $ids = array_merge($ids, $manual);
        }
        return array_unique(array_filter($ids));
    }
    
    private function is_excluded_url($url) {
        if (empty($this->options['exclude_urls'])) return false;
        $patterns = array_filter(array_map('trim', explode("\n", $this->options['exclude_urls'])));
        foreach ($patterns as $pattern) {
            if (!empty($pattern) && strpos($url, $pattern) !== false) return true;
        }
        return false;
    }
    
    private function calc_changefreq($modified) {
        $diff = time() - strtotime($modified);
        if ($diff < DAY_IN_SECONDS) return 'daily';
        if ($diff < WEEK_IN_SECONDS) return 'weekly';
        if ($diff < MONTH_IN_SECONDS) return 'monthly';
        return 'yearly';
    }
    
    private function is_woocommerce() {
        return class_exists('WooCommerce');
    }
    
    // =============================================================
    //  NOTIFICA GOOGLE
    // =============================================================
    
    public function ping_google() {
        $sitemap_url = home_url('/sitemap.xml');
        $google_ping = 'https://www.google.com/ping?sitemap=' . urlencode($sitemap_url);
        $response = wp_remote_get($google_ping, array('timeout' => 10));
        $code = wp_remote_retrieve_response_code($response);
        
        $this->options['last_google_ping'] = current_time('mysql');
        $this->options['last_google_ping_status'] = is_wp_error($response) 
            ? 'Errore: ' . $response->get_error_message() 
            : 'HTTP ' . $code . ($code === 200 ? ' OK' : ($code === 404 ? ' (deprecated, OK)' : ''));
        update_option('osg_options', $this->options);
        
        if (class_exists('OSG_IndexNow')) {
            $indexnow = OSG_IndexNow::get_instance();
            $indexnow->log('Google ping: HTTP ' . $code . ' for ' . $sitemap_url);
        }
    }
    
    // =============================================================
    //  EVENTI CONTENUTO
    // =============================================================
    
    public function on_content_change($post_id = null, $post = null) {
        if ($post_id && (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id))) return;
        if (empty($this->options['auto_regenerate'])) return;
        
        $this->options['last_generated'] = current_time('mysql');
        update_option('osg_options', $this->options);
        
        if (!empty($this->options['ping_google_on_change'])) {
            if (!wp_next_scheduled('osg_notify_google')) {
                wp_schedule_single_event(time() + 300, 'osg_notify_google');
            }
        }
    }
    
    // =============================================================
    //  ROBOTS.TXT
    // =============================================================
    
    public function add_sitemap_to_robots($output, $public) {
        if ($public) {
            $sitemap_url = home_url('/sitemap.xml');
            if (strpos($output, $sitemap_url) === false) {
                $output .= "\n# Open Sitemap Generator\nSitemap: {$sitemap_url}\n";
            }
        }
        return $output;
    }
    
    // =============================================================
    //  ADMIN
    // =============================================================
    
    public function add_admin_menu() {
        add_options_page(
            'Open Sitemap Generator',
            'Open Sitemap',
            'manage_options',
            'open-sitemap-generator',
            array($this, 'render_admin_page')
        );
    }
    
    public function register_settings() {
        register_setting('osg_settings_group', 'osg_options', array($this, 'sanitize_options'));
    }
    
    public function sanitize_options($input) {
        $s = array();
        $checkboxes = array(
            'include_pages', 'include_posts', 'include_products',
            'include_categories', 'include_product_categories', 'include_tags',
            'auto_regenerate', 'ping_google_on_change',
        );
        foreach ($checkboxes as $key) {
            $s[$key] = !empty($input[$key]);
        }
        
        $s['exclude_urls']          = sanitize_textarea_field($input['exclude_urls'] ?? '');
        $s['exclude_ids']           = sanitize_text_field($input['exclude_ids'] ?? '');
        $s['priority_home']         = number_format(floatval($input['priority_home'] ?? 1.0), 1);
        $s['priority_pages']        = number_format(floatval($input['priority_pages'] ?? 0.8), 1);
        $s['priority_posts']        = number_format(floatval($input['priority_posts'] ?? 0.6), 1);
        $s['priority_products']     = number_format(floatval($input['priority_products'] ?? 0.7), 1);
        $s['priority_categories']   = number_format(floatval($input['priority_categories'] ?? 0.7), 1);
        $s['max_urls_per_sitemap']  = max(1000, min(50000, intval($input['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP)));
        
        $s['last_generated']         = $this->options['last_generated'] ?? '';
        $s['last_google_ping']       = $this->options['last_google_ping'] ?? '';
        $s['last_google_ping_status']= $this->options['last_google_ping_status'] ?? '';
        
        flush_rewrite_rules();
        return $s;
    }
    
    public function admin_scripts($hook) {
        if ($hook !== 'settings_page_open-sitemap-generator') return;
        wp_enqueue_style('osg-admin', OSG_URL . 'admin/style.css', array(), OSG_VERSION);
        wp_enqueue_script('osg-admin', OSG_URL . 'admin/script.js', array('jquery'), OSG_VERSION, true);
        wp_localize_script('osg-admin', 'osgAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('osg_nonce'),
        ));
    }
    
    public function settings_link($links) {
        array_unshift($links, '<a href="options-general.php?page=open-sitemap-generator">Settings</a>');
        return $links;
    }
    
    // =============================================================
    //  AJAX
    // =============================================================
    
    public function ajax_generate_sitemap() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        $this->options['last_generated'] = current_time('mysql');
        update_option('osg_options', $this->options);
        flush_rewrite_rules();
        
        wp_send_json_success(array(
            'message' => 'Sitemap updated! Rewrite rules flushed.',
            'time'    => wp_date('d/m/Y H:i:s'),
        ));
    }
    
    public function ajax_ping_google() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied');
        
        $this->ping_google();
        
        wp_send_json_success(array(
            'message' => 'Google ping sent! Status: ' . ($this->options['last_google_ping_status'] ?? 'N/A'),
            'status'  => $this->options['last_google_ping_status'] ?? 'N/A',
        ));
    }
    
    // =============================================================
    //  STATISTICHE PER ADMIN
    // =============================================================
    
    public function get_stats() {
        $max_url = intval($this->options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP);
        
        $pages_count    = $this->count_published('page');
        $posts_count    = $this->count_published('post');
        $products_count = $this->is_woocommerce() ? $this->count_published('product') : 0;
        $cats_count     = $this->count_terms('category');
        $prod_cats      = $this->is_woocommerce() ? $this->count_terms('product_cat') : 0;
        
        return array(
            'pages'              => $pages_count,
            'posts'              => $posts_count,
            'products'           => $products_count,
            'categories'         => $cats_count,
            'product_cats'       => $prod_cats,
            'total_urls'         => $pages_count + $posts_count + $products_count + $cats_count + $prod_cats + 1,
            'sitemap_files'      => $this->count_sitemap_files(),
            'max_urls_per_file'  => $max_url,
            'last_generated'     => $this->options['last_generated'] ?? '',
            'last_google_ping'   => $this->options['last_google_ping'] ?? '',
            'last_google_ping_status' => $this->options['last_google_ping_status'] ?? '',
        );
    }
    
    private function count_sitemap_files() {
        $max_url = intval($this->options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP);
        $files = 0;
        if (!empty($this->options['include_pages'])) $files += max(1, ceil($this->count_published('page') / $max_url));
        if (!empty($this->options['include_posts']) && $this->count_published('post') > 0) $files += max(1, ceil($this->count_published('post') / $max_url));
        if (!empty($this->options['include_products']) && $this->is_woocommerce()) {
            $c = $this->count_published('product');
            if ($c > 0) $files += max(1, ceil($c / $max_url));
        }
        if (!empty($this->options['include_categories']) || !empty($this->options['include_product_categories'])) $files += 1;
        return $files + 1;
    }
    
    public function get_options() { return $this->options; }
    
    public function render_admin_page() {
        require_once OSG_PATH . 'admin/settings-page.php';
    }
}

// Avvia il plugin
add_action('plugins_loaded', function() {
    Open_Sitemap_Generator::get_instance();
});

// Carica IndexNow
require_once OSG_PATH . 'includes/class-indexnow.php';

// Carica Rich Results (Schema Enhancement per Google)
require_once OSG_PATH . 'includes/class-rich-results.php';
