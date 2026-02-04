<?php
/**
 * IndexNow Support per Open Sitemap Generator
 * 
 * Versione 1.2.0 - Sistema a coda con invio temporizzato
 * 
 * Fix rispetto v1.1.0:
 * - Hook prodotti: usa flag statico per evitare accodamento multiplo
 * - Rilevamento stock: confronto campi reali pre/post save
 * - Coda: salvata con autoload=no per non appesantire ogni pageload
 * - Quota: gestione coerente singolo/batch
 * 
 * @package Open_Sitemap_Generator
 * @since 1.2.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OSG_IndexNow {
    
    private static $instance = null;
    private $api_key;
    private $options;
    
    /**
     * Flag statico: tiene traccia degli ID già accodati in questa request.
     * Evita che hook multipli (save_post + post_updated + save_post_product)
     * accodino lo stesso URL più volte.
     */
    private static $queued_this_request = array();
    
    /**
     * Flag statico: se true, siamo in un aggiornamento stock WooCommerce.
     * Impostato da hook WooCommerce PRIMA del save_post.
     */
    private static $is_stock_update = false;
    
    /**
     * Snapshot dei campi prodotto PRIMA del salvataggio.
     * Usato per confrontare se è cambiato solo lo stock.
     */
    private static $pre_save_snapshot = array();
    
    // Endpoint IndexNow
    private $endpoints = array(
        'bing'   => 'https://www.bing.com/indexnow',
        'yandex' => 'https://yandex.com/indexnow',
    );
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->options = get_option('osg_indexnow_options', $this->get_default_options());
        $this->api_key = $this->get_or_create_api_key();
        
        // === HOOK PER ACCODAMENTO (ordine importante!) ===
        
        // 1. Cattura snapshot PRE-save per prodotti (priorità bassa = esegue prima)
        add_action('pre_post_update', array($this, 'capture_pre_save_snapshot'), 5, 2);
        
        // 2. Rileva aggiornamenti stock WooCommerce (prima di save_post)
        add_action('woocommerce_product_set_stock', array($this, 'flag_stock_update'), 5);
        add_action('woocommerce_variation_set_stock', array($this, 'flag_stock_update'), 5);
        
        // 3. Hook principale: intercetta tutti i salvataggi (priorità alta)
        add_action('save_post', array($this, 'on_save_post'), 20, 2);
        
        // 4. Nuove pubblicazioni (transition da draft/pending → publish)
        add_action('transition_post_status', array($this, 'on_status_transition'), 20, 3);
        
        // === CRON per invio batch ===
        add_action('osg_indexnow_process_queue', array($this, 'process_queue'));
        add_action('init', array($this, 'setup_cron'));
        
        // === Admin ===
        add_action('admin_init', array($this, 'register_settings'));
        
        // === AJAX ===
        add_action('wp_ajax_osg_indexnow_submit', array($this, 'ajax_submit_url'));
        add_action('wp_ajax_osg_indexnow_bulk', array($this, 'ajax_bulk_submit'));
        add_action('wp_ajax_osg_indexnow_process_now', array($this, 'ajax_process_now'));
        add_action('wp_ajax_osg_indexnow_clear_queue', array($this, 'ajax_clear_queue'));
        
        // === Rewrite per file chiave ===
        add_action('init', array($this, 'add_key_rewrite'));
        add_filter('query_vars', array($this, 'register_query_vars'));
        add_action('template_redirect', array($this, 'serve_key_file'));
    }
    
    // =============================================================
    //  OPZIONI DEFAULT
    // =============================================================
    
    private function get_default_options() {
        return array(
            'enabled'                => true,
            'auto_submit_posts'      => true,
            'auto_submit_pages'      => true,
            'auto_submit_products'   => true,
            'endpoint'               => 'bing',
            'daily_quota'            => 10000,
            'submitted_today'        => 0,
            'last_reset'             => '',
            'log_enabled'            => true,
            'queue_mode'             => 'hourly',
            'exclude_stock_updates'  => true,
            'batch_size'             => 100,
        );
    }
    
    // =============================================================
    //  CRON SETUP
    // =============================================================
    
    public function setup_cron() {
        $mode = $this->options['queue_mode'] ?? 'hourly';
        
        if ($mode === 'immediate') {
            wp_clear_scheduled_hook('osg_indexnow_process_queue');
            return;
        }
        
        if (!wp_next_scheduled('osg_indexnow_process_queue')) {
            wp_schedule_event(time(), $mode, 'osg_indexnow_process_queue');
        }
    }
    
    public function reschedule_cron() {
        wp_clear_scheduled_hook('osg_indexnow_process_queue');
        $this->setup_cron();
    }
    
    // =============================================================
    //  CHIAVE API E FILE DI VERIFICA
    // =============================================================
    
    private function get_or_create_api_key() {
        $key = get_option('osg_indexnow_api_key');
        
        if (empty($key)) {
            $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
            $key = '';
            for ($i = 0; $i < 32; $i++) {
                $key .= $chars[wp_rand(0, strlen($chars) - 1)];
            }
            update_option('osg_indexnow_api_key', $key);
        }
        
        return $key;
    }
    
    public function add_key_rewrite() {
        add_rewrite_rule('^' . preg_quote($this->api_key) . '\.txt$', 'index.php?osg_indexnow_key=1', 'top');
    }
    
    public function register_query_vars($vars) {
        $vars[] = 'osg_indexnow_key';
        return $vars;
    }
    
    public function serve_key_file() {
        // Via query var (rewrite rule)
        if (get_query_var('osg_indexnow_key')) {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $this->api_key;
            exit;
        }
        
        // Fallback: via REQUEST_URI diretto
        $request_uri = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        if ($request_uri === $this->api_key . '.txt') {
            header('Content-Type: text/plain; charset=UTF-8');
            echo $this->api_key;
            exit;
        }
    }
    
    // =============================================================
    //  RILEVAMENTO CAMBIAMENTI (Fix stock-only detection)
    // =============================================================
    
    /**
     * Cattura uno snapshot dei campi importanti PRIMA del salvataggio.
     * Così possiamo confrontare dopo per capire se è cambiato solo lo stock.
     */
    public function capture_pre_save_snapshot($post_id, $data) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'product') return;
        
        self::$pre_save_snapshot[$post_id] = array(
            'post_title'   => $post->post_title,
            'post_content' => $post->post_content,
            'post_excerpt' => $post->post_excerpt,
            '_regular_price' => get_post_meta($post_id, '_regular_price', true),
            '_sale_price'    => get_post_meta($post_id, '_sale_price', true),
            '_sku'           => get_post_meta($post_id, '_sku', true),
        );
    }
    
    /**
     * Flag da WooCommerce: siamo in un aggiornamento stock
     */
    public function flag_stock_update($product) {
        if (is_object($product)) {
            self::$is_stock_update = true;
        }
    }
    
    /**
     * Verifica se un prodotto ha avuto cambiamenti significativi (non solo stock).
     * Confronta: titolo, contenuto, estratto, prezzo, SKU.
     */
    private function has_meaningful_changes($post_id) {
        if (!isset(self::$pre_save_snapshot[$post_id])) {
            return true; // Nessuno snapshot → presumi che sia cambiato
        }
        
        $before = self::$pre_save_snapshot[$post_id];
        $post   = get_post($post_id);
        
        // Confronta campi post
        if ($before['post_title'] !== $post->post_title) return true;
        if ($before['post_content'] !== $post->post_content) return true;
        if ($before['post_excerpt'] !== $post->post_excerpt) return true;
        
        // Confronta meta importanti
        if ($before['_regular_price'] !== get_post_meta($post_id, '_regular_price', true)) return true;
        if ($before['_sale_price'] !== get_post_meta($post_id, '_sale_price', true)) return true;
        if ($before['_sku'] !== get_post_meta($post_id, '_sku', true)) return true;
        
        return false; // Solo stock/quantità è cambiato
    }
    
    // =============================================================
    //  HOOKS WORDPRESS (punto unico di ingresso)
    // =============================================================
    
    /**
     * Hook principale per tutti i salvataggi.
     * Punto unico di ingresso per evitare accodamento multiplo.
     */
    public function on_save_post($post_id, $post) {
        if (!$this->options['enabled']) return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if ($post->post_status !== 'publish') return;
        
        // Già accodato in questa request? Skip.
        if (in_array($post_id, self::$queued_this_request)) return;
        
        $type = $post->post_type;
        
        // Verifica tipo abilitato
        if ($type === 'post' && empty($this->options['auto_submit_posts'])) return;
        if ($type === 'page' && empty($this->options['auto_submit_pages'])) return;
        if ($type === 'product' && empty($this->options['auto_submit_products'])) return;
        
        // Tipi non tracciati
        if (!in_array($type, array('post', 'page', 'product'))) return;
        
        // Per prodotti: esclusione aggiornamenti stock
        if ($type === 'product' && !empty($this->options['exclude_stock_updates'])) {
            if (!$this->has_meaningful_changes($post_id)) {
                // Solo stock è cambiato → non accodare
                return;
            }
        }
        
        $url = get_permalink($post_id);
        if (!$url) return;
        
        $this->add_to_queue($url, 'save_' . $type);
        self::$queued_this_request[] = $post_id;
    }
    
    /**
     * Cattura nuove pubblicazioni (da draft/pending → publish)
     */
    public function on_status_transition($new_status, $old_status, $post) {
        if (!$this->options['enabled']) return;
        if ($new_status !== 'publish' || $old_status === 'publish') return;
        
        $post_id = $post->ID;
        
        // Già accodato?
        if (in_array($post_id, self::$queued_this_request)) return;
        
        $type = $post->post_type;
        if ($type === 'post' && empty($this->options['auto_submit_posts'])) return;
        if ($type === 'page' && empty($this->options['auto_submit_pages'])) return;
        if ($type === 'product' && empty($this->options['auto_submit_products'])) return;
        if (!in_array($type, array('post', 'page', 'product'))) return;
        
        $url = get_permalink($post_id);
        if (!$url) return;
        
        $this->add_to_queue($url, 'new_' . $type);
        self::$queued_this_request[] = $post_id;
    }
    
    // =============================================================
    //  SISTEMA A CODA (non-autoload)
    // =============================================================
    
    /**
     * Aggiunge URL alla coda.
     * 
     * La coda è salvata in wp_options con autoload='no' per non
     * appesantire ogni pageload quando ci sono migliaia di URL.
     */
    public function add_to_queue($url, $source = 'auto') {
        if (!$this->options['enabled']) return false;
        
        $queue = $this->get_queue();
        
        // Evita duplicati (controlla solo URL, non source)
        foreach ($queue as $item) {
            if ($item['url'] === $url) return true; // Già in coda
        }
        
        $queue[] = array(
            'url'    => $url,
            'source' => $source,
            'added'  => current_time('mysql'),
        );
        
        $this->save_queue($queue);
        
        // Se modalità immediata, processa subito
        if (($this->options['queue_mode'] ?? 'hourly') === 'immediate') {
            $this->submit_url($url);
            $this->remove_from_queue($url);
        }
        
        return true;
    }
    
    /**
     * Recupera la coda (non-autoload)
     */
    public function get_queue() {
        // get_option carica comunque da DB se autoload=no, ma non la mette in cache alloptions
        $queue = get_option('osg_indexnow_queue', array());
        return is_array($queue) ? $queue : array();
    }
    
    /**
     * Salva la coda con autoload='no'
     */
    private function save_queue($queue) {
        // Verifica se l'opzione esiste già
        if (false === get_option('osg_indexnow_queue', false)) {
            add_option('osg_indexnow_queue', $queue, '', 'no');
        } else {
            update_option('osg_indexnow_queue', $queue, 'no');
        }
    }
    
    private function remove_from_queue($url) {
        $queue = $this->get_queue();
        $queue = array_values(array_filter($queue, function($item) use ($url) {
            return $item['url'] !== $url;
        }));
        $this->save_queue($queue);
    }
    
    public function clear_queue() {
        $this->save_queue(array());
    }
    
    /**
     * Processa la coda (chiamato dal cron o manualmente)
     */
    public function process_queue() {
        $queue = $this->get_queue();
        
        if (empty($queue)) {
            $this->log('Coda vuota, nessun URL da processare');
            return 0;
        }
        
        $batch_size  = intval($this->options['batch_size'] ?? 100);
        $to_send     = array_slice($queue, 0, $batch_size);
        $urls        = array_column($to_send, 'url');
        
        $this->log('Processo coda: ' . count($urls) . ' URL da inviare (totale in coda: ' . count($queue) . ')');
        
        $result = $this->submit_urls_batch($urls);
        
        if ($result) {
            $remaining = array_slice($queue, $batch_size);
            $this->save_queue($remaining);
            $this->log('✓ Batch completato. URL rimanenti in coda: ' . count($remaining));
            return count($urls);
        } else {
            $this->log('✗ Errore invio batch, coda invariata');
            return 0;
        }
    }
    
    // =============================================================
    //  INVIO A INDEXNOW
    // =============================================================
    
    /**
     * Invia singolo URL a IndexNow (GET request)
     */
    public function submit_url($url) {
        if (!$this->check_and_reset_quota()) {
            $this->log('Quota giornaliera raggiunta (' . $this->options['daily_quota'] . '), URL non inviato: ' . $url);
            return false;
        }
        
        $endpoint = $this->endpoints[$this->options['endpoint']] ?? $this->endpoints['bing'];
        
        $request_url = add_query_arg(array(
            'url' => urlencode($url),
            'key' => $this->api_key,
        ), $endpoint);
        
        $response = wp_remote_get($request_url, array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json'),
        ));
        
        if (is_wp_error($response)) {
            $this->log('Errore IndexNow: ' . $response->get_error_message() . ' - URL: ' . $url);
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $this->increment_quota(1);
        
        if ($code === 200 || $code === 202) {
            $this->log('✓ URL inviato (' . $code . '): ' . $url);
            return true;
        } else {
            $this->log('✗ IndexNow HTTP ' . $code . ' per: ' . $url);
            return false;
        }
    }
    
    /**
     * Invia multipli URL in batch (POST request)
     */
    public function submit_urls_batch($urls) {
        if (empty($urls)) return false;
        
        if (!$this->check_and_reset_quota()) {
            $this->log('Quota giornaliera raggiunta');
            return false;
        }
        
        // Limita a quota disponibile
        $available = $this->options['daily_quota'] - $this->options['submitted_today'];
        $urls = array_slice($urls, 0, min(count($urls), $available, 10000));
        
        if (empty($urls)) return false;
        
        $host     = parse_url(home_url(), PHP_URL_HOST);
        $endpoint = $this->endpoints[$this->options['endpoint']] ?? $this->endpoints['bing'];
        
        $body = array(
            'host'        => $host,
            'key'         => $this->api_key,
            'keyLocation' => home_url('/' . $this->api_key . '.txt'),
            'urlList'     => array_values($urls),
        );
        
        $response = wp_remote_post($endpoint, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8'),
            'body'    => wp_json_encode($body),
        ));
        
        if (is_wp_error($response)) {
            $this->log('Errore IndexNow batch: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code === 200 || $code === 202) {
            $this->increment_quota(count($urls));
            $this->log('✓ Batch di ' . count($urls) . ' URL inviato (HTTP ' . $code . ')');
            return true;
        } else {
            $body_response = wp_remote_retrieve_body($response);
            $this->log('✗ IndexNow batch HTTP ' . $code . ': ' . substr($body_response, 0, 200));
            return false;
        }
    }
    
    // =============================================================
    //  QUOTA GIORNALIERA
    // =============================================================
    
    private function check_and_reset_quota() {
        // Usa il fuso orario di WordPress (es. Europe/Rome), NON UTC
        $today = wp_date('Y-m-d');
        
        if (($this->options['last_reset'] ?? '') !== $today) {
            $this->options['submitted_today'] = 0;
            $this->options['last_reset'] = $today;
            update_option('osg_indexnow_options', $this->options);
        }
        
        return $this->options['submitted_today'] < $this->options['daily_quota'];
    }
    
    private function increment_quota($count = 1) {
        $this->options['submitted_today'] += $count;
        update_option('osg_indexnow_options', $this->options);
    }
    
    // =============================================================
    //  LOG
    // =============================================================
    
    public function log($message) {
        if (empty($this->options['log_enabled'])) return;
        
        $log = get_option('osg_indexnow_log', array());
        if (!is_array($log)) $log = array();
        
        array_unshift($log, array(
            'time'    => current_time('mysql'),
            'message' => $message,
        ));
        
        // Mantieni solo ultimi 100 entry
        $log = array_slice($log, 0, 100);
        update_option('osg_indexnow_log', $log);
    }
    
    // =============================================================
    //  ADMIN SETTINGS
    // =============================================================
    
    public function register_settings() {
        register_setting('osg_settings_group', 'osg_indexnow_options', array($this, 'sanitize_options'));
    }
    
    public function sanitize_options($input) {
        $s = array();
        
        $s['enabled']               = !empty($input['enabled']);
        $s['auto_submit_posts']     = !empty($input['auto_submit_posts']);
        $s['auto_submit_pages']     = !empty($input['auto_submit_pages']);
        $s['auto_submit_products']  = !empty($input['auto_submit_products']);
        $s['log_enabled']           = !empty($input['log_enabled']);
        $s['exclude_stock_updates'] = !empty($input['exclude_stock_updates']);
        $s['endpoint']              = sanitize_text_field($input['endpoint'] ?? 'bing');
        $s['daily_quota']           = max(100, absint($input['daily_quota'] ?? 10000));
        $s['batch_size']            = max(10, min(10000, absint($input['batch_size'] ?? 100)));
        $s['queue_mode']            = sanitize_text_field($input['queue_mode'] ?? 'hourly');
        
        // Preserva campi interni
        $s['submitted_today'] = $this->options['submitted_today'] ?? 0;
        $s['last_reset']      = $this->options['last_reset'] ?? '';
        
        // Rischedula cron se cambia la frequenza
        if (($this->options['queue_mode'] ?? 'hourly') !== $s['queue_mode']) {
            $this->options = $s; // Aggiorna prima per reschedule_cron
            $this->reschedule_cron();
        }
        
        return $s;
    }
    
    // =============================================================
    //  AJAX
    // =============================================================
    
    public function ajax_submit_url() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato');
        
        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (empty($url)) wp_send_json_error('URL non valido');
        
        $result = $this->submit_url($url);
        
        if ($result) {
            wp_send_json_success(array('message' => 'URL inviato a IndexNow!', 'url' => $url));
        } else {
            wp_send_json_error('Errore durante l\'invio');
        }
    }
    
    public function ajax_bulk_submit() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato');
        
        global $wpdb;
        
        // Query diretta DB per performance (non get_posts con -1!)
        $post_types = array('post', 'page');
        if (class_exists('WooCommerce')) $post_types[] = 'product';
        
        $placeholders = implode(',', array_fill(0, count($post_types), '%s'));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type IN ({$placeholders})
               AND post_status = 'publish'
             ORDER BY post_modified DESC",
            $post_types
        ));
        
        if (empty($ids)) wp_send_json_error('Nessun URL da inviare');
        
        $urls = array();
        foreach ($ids as $id) {
            $url = get_permalink($id);
            if ($url) $urls[] = $url;
        }
        
        if (empty($urls)) wp_send_json_error('Nessun URL da inviare');
        
        // Invia in batch da 10.000 (limite IndexNow)
        $total_sent = 0;
        $chunks = array_chunk($urls, 10000);
        
        foreach ($chunks as $chunk) {
            $result = $this->submit_urls_batch($chunk);
            if ($result) {
                $total_sent += count($chunk);
            }
        }
        
        if ($total_sent > 0) {
            wp_send_json_success(array(
                'message' => $total_sent . ' URL inviati a IndexNow!',
                'count'   => $total_sent,
            ));
        } else {
            wp_send_json_error('Errore durante l\'invio batch');
        }
    }
    
    public function ajax_process_now() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato');
        
        $queue = $this->get_queue();
        if (empty($queue)) {
            wp_send_json_error('La coda è vuota');
        }
        
        $sent = $this->process_queue();
        $remaining = count($this->get_queue());
        
        wp_send_json_success(array(
            'message'   => "Coda processata! {$sent} URL inviati.",
            'sent'      => $sent,
            'remaining' => $remaining,
        ));
    }
    
    public function ajax_clear_queue() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permesso negato');
        
        $count = count($this->get_queue());
        $this->clear_queue();
        
        $this->log("Coda svuotata manualmente ({$count} URL rimossi)");
        
        wp_send_json_success(array('message' => "Coda svuotata ({$count} URL rimossi)"));
    }
    
    // =============================================================
    //  GETTERS PER ADMIN
    // =============================================================
    
    public function get_stats() {
        $queue    = $this->get_queue();
        $next_run = wp_next_scheduled('osg_indexnow_process_queue');
        
        return array(
            'api_key'         => $this->api_key,
            'key_url'         => home_url('/' . $this->api_key . '.txt'),
            'submitted_today' => $this->options['submitted_today'] ?? 0,
            'daily_quota'     => $this->options['daily_quota'] ?? 10000,
            'enabled'         => !empty($this->options['enabled']),
            'endpoint'        => $this->options['endpoint'] ?? 'bing',
            'queue_count'     => count($queue),
            'queue_mode'      => $this->options['queue_mode'] ?? 'hourly',
            'next_process'    => $next_run ? wp_date('d/m/Y H:i:s', $next_run) : 'N/A',
            'batch_size'      => $this->options['batch_size'] ?? 100,
        );
    }
    
    public function get_options() {
        return $this->options;
    }
    
    public function get_log($limit = 20) {
        $log = get_option('osg_indexnow_log', array());
        return is_array($log) ? array_slice($log, 0, $limit) : array();
    }
}

// Inizializza
add_action('plugins_loaded', function() {
    OSG_IndexNow::get_instance();
}, 20);
