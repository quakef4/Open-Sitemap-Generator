<?php
/**
 * Pagina impostazioni admin per Open Sitemap
 * Versione 1.2.0 con paginazione, Google ping, coda temporizzata
 */

if (!defined('ABSPATH')) exit;

$sitemap = Open_Sitemap_Generator::get_instance();
$stats = $sitemap->get_stats();
$options = $sitemap->get_options();

// IndexNow
$indexnow = OSG_IndexNow::get_instance();
$indexnow_stats = $indexnow->get_stats();
$indexnow_options = $indexnow->get_options();
$indexnow_log = $indexnow->get_log(10);
$indexnow_queue = $indexnow->get_queue();
?>

<div class="wrap osg-wrap">
    <h1>
        <span class="dashicons dashicons-sitemap"></span>
        Open Sitemap Generator
        <span class="version">v<?php echo OSG_VERSION; ?></span>
    </h1>
    
    <!-- Statistiche -->
    <div class="infobit-stats-grid">
        <div class="stat-card">
            <div class="stat-icon pages"></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo esc_html($stats['pages']); ?></span>
                <span class="stat-label">Pagine</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon posts"></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo esc_html($stats['posts']); ?></span>
                <span class="stat-label">Articoli</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon products"></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo number_format_i18n($stats['products']); ?></span>
                <span class="stat-label">Prodotti</span>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon categories"></div>
            <div class="stat-content">
                <span class="stat-number"><?php echo esc_html($stats['categories'] + $stats['product_cats']); ?></span>
                <span class="stat-label">Categorie</span>
            </div>
        </div>
    </div>
    
    <!-- Riepilogo Sitemap -->
    <div class="infobit-sitemap-links">
        <h2>🗂️ Struttura Sitemap</h2>
        
        <div class="sitemap-summary">
            <p>
                <strong>URL totali:</strong> <?php echo number_format_i18n($stats['total_urls']); ?> |
                <strong>File sitemap:</strong> <?php echo esc_html($stats['sitemap_files']); ?> |
                <strong>Max URL per file:</strong> <?php echo number_format_i18n($stats['max_urls_per_file']); ?>
            </p>
            <?php if ($stats['products'] > $stats['max_urls_per_file']): ?>
            <p class="description">
                ℹ️ I prodotti sono suddivisi in <strong><?php echo ceil($stats['products'] / $stats['max_urls_per_file']); ?> file sitemap</strong> 
                per rispettare il limite di <?php echo number_format_i18n($stats['max_urls_per_file']); ?> URL per file.
            </p>
            <?php endif; ?>
        </div>
        
        <table class="widefat">
            <thead>
                <tr>
                    <th>Sitemap</th>
                    <th>URL</th>
                    <th>Contenuti</th>
                    <th>Azione</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>📋 Index</strong></td>
                    <td><code><?php echo esc_url(home_url('/sitemap.xml')); ?></code></td>
                    <td>Indice di tutte le sitemap</td>
                    <td><a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>" target="_blank" class="button button-small">Apri ↗</a></td>
                </tr>
                <?php
                $max_url = $stats['max_urls_per_file'];
                
                // Pagine
                if (!empty($options['include_pages'])):
                    $page_files = max(1, ceil($stats['pages'] / $max_url));
                    for ($i = 1; $i <= $page_files; $i++):
                ?>
                <tr>
                    <td>Pagine<?php echo $page_files > 1 ? " ({$i}/{$page_files})" : ''; ?></td>
                    <td><code><?php echo esc_url(home_url("/sitemap-pages-{$i}.xml")); ?></code></td>
                    <td><?php echo min($stats['pages'] - ($i-1)*$max_url, $max_url); ?> URL</td>
                    <td><a href="<?php echo esc_url(home_url("/sitemap-pages-{$i}.xml")); ?>" target="_blank" class="button button-small">Apri ↗</a></td>
                </tr>
                <?php endfor; endif; ?>
                
                <?php
                // Prodotti
                if (!empty($options['include_products']) && $stats['products'] > 0):
                    $prod_files = max(1, ceil($stats['products'] / $max_url));
                    for ($i = 1; $i <= $prod_files; $i++):
                        $urls_in_file = min($stats['products'] - ($i-1)*$max_url, $max_url);
                ?>
                <tr>
                    <td>Prodotti<?php echo $prod_files > 1 ? " ({$i}/{$prod_files})" : ''; ?></td>
                    <td><code><?php echo esc_url(home_url("/sitemap-products-{$i}.xml")); ?></code></td>
                    <td><?php echo number_format_i18n($urls_in_file); ?> URL</td>
                    <td><a href="<?php echo esc_url(home_url("/sitemap-products-{$i}.xml")); ?>" target="_blank" class="button button-small">Apri ↗</a></td>
                </tr>
                <?php endfor; endif; ?>
                
                <?php if (!empty($options['include_categories']) || !empty($options['include_product_categories'])): ?>
                <tr>
                    <td>Categorie</td>
                    <td><code><?php echo esc_url(home_url('/sitemap-categories-1.xml')); ?></code></td>
                    <td><?php echo esc_html($stats['categories'] + $stats['product_cats']); ?> URL</td>
                    <td><a href="<?php echo esc_url(home_url('/sitemap-categories-1.xml')); ?>" target="_blank" class="button button-small">Apri ↗</a></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Azioni Rapide -->
    <div class="infobit-actions">
        <h2>⚡ Azioni Rapide</h2>
        <p>
            <button type="button" id="btn-regenerate" class="button button-primary button-hero">
                <span class="dashicons dashicons-update"></span>
                Rigenera Sitemap
            </button>
            <button type="button" id="btn-ping-google" class="button button-hero">
                <span class="dashicons dashicons-megaphone"></span>
                Ping Google
            </button>
        </p>
        <div id="action-result" class="notice" style="display:none;"></div>
        
        <div class="infobit-last-update">
            <p>
                <strong>Ultima generazione:</strong> 
                <span id="last-generated"><?php echo $stats['last_generated'] ? esc_html(wp_date('d/m/Y H:i:s', strtotime($stats['last_generated']))) : 'Mai'; ?></span>
            </p>
            <p>
                <strong>Ultimo ping Google:</strong> 
                <?php echo $stats['last_google_ping'] ? esc_html(wp_date('d/m/Y H:i:s', strtotime($stats['last_google_ping']))) : 'Mai'; ?>
                <?php if (!empty($stats['last_google_ping_status'])): ?>
                    — <em><?php echo esc_html($stats['last_google_ping_status']); ?></em>
                <?php endif; ?>
            </p>
        </div>
    </div>
    
    <!-- ==================== SEZIONE GOOGLE ==================== -->
    <div class="infobit-settings-section google-section">
        <h2>🔍 Google - Notifica Sitemap</h2>
        
        <div class="google-info-banner">
            <p><strong>Come Google scopre la tua sitemap:</strong></p>
            <ul style="margin:5px 0 0 20px;">
                <li>✅ <strong>robots.txt</strong> — Il plugin aggiunge automaticamente <code>Sitemap: <?php echo esc_html(home_url('/sitemap.xml')); ?></code></li>
                <li>✅ <strong>Google Search Console</strong> — Registra la sitemap manualmente una volta</li>
                <li>✅ <strong>Crawling automatico</strong> — Google visita la sitemap regolarmente</li>
                <li>⚠️ <strong>Ping sitemap</strong> — Deprecato da Google (giugno 2023), ma il plugin lo tenta comunque</li>
            </ul>
            <p style="margin-top:10px;"><strong>⚠️ Google NON supporta IndexNow.</strong> IndexNow funziona solo per Bing e Yandex. 
            Per Google il metodo principale resta la sitemap in robots.txt + Search Console.</p>
        </div>
    </div>
    
    <!-- ==================== SEZIONE INDEXNOW ==================== -->
    <div class="infobit-settings-section indexnow-section">
        <h2>🚀 IndexNow - Notifica Istantanea a Bing/Yandex</h2>
        
        <div class="indexnow-info-banner">
            <p><strong>IndexNow</strong> notifica istantaneamente Bing e Yandex quando modifichi contenuti.
            Gli URL vengono accodati e inviati in batch per non rallentare il sito.</p>
        </div>
        
        <!-- Stato IndexNow -->
        <div class="indexnow-status">
            <h3>📊 Stato</h3>
            <table class="form-table">
                <tr>
                    <th>Chiave API</th>
                    <td><code><?php echo esc_html($indexnow_stats['api_key']); ?></code></td>
                </tr>
                <tr>
                    <th>File di verifica</th>
                    <td>
                        <a href="<?php echo esc_url($indexnow_stats['key_url']); ?>" target="_blank" class="code-link">
                            <?php echo esc_html($indexnow_stats['key_url']); ?>
                        </a>
                        <span id="key-file-status"></span>
                        <button type="button" id="btn-verify-key" class="button button-small">Verifica</button>
                    </td>
                </tr>
                <tr>
                    <th>URL inviati oggi</th>
                    <td>
                        <strong><?php echo esc_html($indexnow_stats['submitted_today']); ?></strong> / <?php echo esc_html($indexnow_stats['daily_quota']); ?>
                        <div class="quota-bar">
                            <div class="quota-fill" style="width: <?php echo min(100, ($indexnow_stats['submitted_today'] / max(1, $indexnow_stats['daily_quota'])) * 100); ?>%"></div>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Coda URL -->
        <div class="indexnow-queue-box">
            <h3>📋 Coda URL in Attesa</h3>
            <table class="form-table">
                <tr>
                    <th>URL in coda</th>
                    <td>
                        <strong class="queue-count"><?php echo esc_html($indexnow_stats['queue_count']); ?></strong> URL
                        <?php if ($indexnow_stats['queue_count'] > 0): ?>
                            <span class="queue-status pending">In attesa di invio</span>
                        <?php else: ?>
                            <span class="queue-status empty">Coda vuota</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>Modalità invio</th>
                    <td>
                        <?php
                        $mode_labels = array(
                            'immediate'  => '⚡ Immediato (ogni modifica)',
                            'hourly'     => '🕐 Ogni ora',
                            'twicedaily' => '🕐 Ogni 12 ore',
                            'daily'      => '🕐 Ogni 24 ore',
                        );
                        echo esc_html($mode_labels[$indexnow_stats['queue_mode']] ?? $indexnow_stats['queue_mode']);
                        ?>
                    </td>
                </tr>
                <?php if ($indexnow_stats['queue_mode'] !== 'immediate'): ?>
                <tr>
                    <th>Prossimo invio</th>
                    <td><strong><?php echo esc_html($indexnow_stats['next_process']); ?></strong></td>
                </tr>
                <?php endif; ?>
            </table>
            
            <?php if ($indexnow_stats['queue_count'] > 0): ?>
            <div class="queue-actions">
                <button type="button" id="btn-process-queue" class="button button-primary">
                    <span class="dashicons dashicons-controls-play"></span>
                    Invia Coda Adesso
                </button>
                <button type="button" id="btn-clear-queue" class="button">
                    <span class="dashicons dashicons-trash"></span>
                    Svuota Coda
                </button>
            </div>
            
            <div class="queue-preview">
                <h4>Ultimi URL in coda:</h4>
                <ul>
                    <?php 
                    $preview_queue = array_slice($indexnow_queue, 0, 5);
                    foreach ($preview_queue as $item): 
                    ?>
                    <li>
                        <code><?php echo esc_html($item['url']); ?></code>
                        <small>(<?php echo esc_html($item['source'] ?? ''); ?> — <?php echo esc_html($item['added'] ?? ''); ?>)</small>
                    </li>
                    <?php endforeach; ?>
                    <?php if (count($indexnow_queue) > 5): ?>
                    <li><em>... e altri <?php echo count($indexnow_queue) - 5; ?> URL</em></li>
                    <?php endif; ?>
                </ul>
            </div>
            <?php endif; ?>
            
            <div id="queue-result" class="notice" style="display:none;"></div>
        </div>
        
        <!-- Invio Massivo -->
        <div class="indexnow-actions-box">
            <h3>⚡ Invio Massivo</h3>
            <p>
                <button type="button" id="btn-indexnow-bulk" class="button button-primary">
                    <span class="dashicons dashicons-upload"></span>
                    Invia TUTTI gli URL a Bing/Yandex
                </button>
                <span class="description">Invia immediatamente tutti i contenuti pubblicati (bypassa la coda).</span>
            </p>
            <div id="indexnow-result" class="notice" style="display:none;"></div>
        </div>
        
        <!-- Log -->
        <?php if (!empty($indexnow_log)): ?>
        <div class="indexnow-log">
            <h3>📜 Log Recenti</h3>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:150px;">Data/Ora</th>
                        <th>Messaggio</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($indexnow_log as $entry): ?>
                    <tr>
                        <td><small><?php echo esc_html(wp_date('d/m/Y H:i:s', strtotime($entry['time']))); ?></small></td>
                        <td><?php echo esc_html($entry['message']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <!-- ==================== FINE INDEXNOW ==================== -->
    
    <!-- ==================== FORM IMPOSTAZIONI ==================== -->
    <form method="post" action="options.php">
        <?php settings_fields('osg_settings_group'); ?>
        
        <div class="infobit-settings-section">
            <h2>📄 Contenuti da Includere</h2>
            <table class="form-table">
                <tr>
                    <th>Tipi di contenuto</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="osg_options[include_pages]" value="1" <?php checked(!empty($options['include_pages'])); ?>>
                                Pagine
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_options[include_posts]" value="1" <?php checked(!empty($options['include_posts'])); ?>>
                                Articoli (Blog)
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_options[include_products]" value="1" <?php checked(!empty($options['include_products'])); ?> <?php echo !class_exists('WooCommerce') ? 'disabled' : ''; ?>>
                                Prodotti WooCommerce
                                <?php if (!class_exists('WooCommerce')): ?>
                                    <em style="color:#999;">(WooCommerce non attivo)</em>
                                <?php endif; ?>
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_options[include_categories]" value="1" <?php checked(!empty($options['include_categories'])); ?>>
                                Categorie articoli
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_options[include_product_categories]" value="1" <?php checked(!empty($options['include_product_categories'])); ?> <?php echo !class_exists('WooCommerce') ? 'disabled' : ''; ?>>
                                Categorie prodotti
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_options[include_tags]" value="1" <?php checked(!empty($options['include_tags'])); ?>>
                                Tag
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="infobit-settings-section">
            <h2>🚫 Esclusioni</h2>
            <table class="form-table">
                <tr>
                    <th><label for="exclude_ids">Escludi per ID</label></th>
                    <td>
                        <input type="text" id="exclude_ids" name="osg_options[exclude_ids]" 
                               value="<?php echo esc_attr($options['exclude_ids'] ?? ''); ?>" class="regular-text">
                        <p class="description">ID separati da virgola (es: 123, 456, 789)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="exclude_urls">Escludi per URL</label></th>
                    <td>
                        <textarea id="exclude_urls" name="osg_options[exclude_urls]" 
                                  rows="5" class="large-text code"><?php echo esc_textarea($options['exclude_urls'] ?? ''); ?></textarea>
                        <p class="description">Un pattern per riga.</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="infobit-settings-section">
            <h2>📊 Priorità Sitemap</h2>
            <table class="form-table">
                <?php
                $priorities = array(
                    'priority_home'       => 'Homepage',
                    'priority_pages'      => 'Pagine',
                    'priority_posts'      => 'Articoli',
                    'priority_products'   => 'Prodotti',
                    'priority_categories' => 'Categorie',
                );
                foreach ($priorities as $key => $label):
                ?>
                <tr>
                    <th><?php echo esc_html($label); ?></th>
                    <td>
                        <select name="osg_options[<?php echo $key; ?>]">
                            <?php for ($i = 10; $i >= 0; $i--): $v = number_format($i/10, 1); ?>
                            <option value="<?php echo $v; ?>" <?php selected($options[$key] ?? '0.5', $v); ?>><?php echo $v; ?></option>
                            <?php endfor; ?>
                        </select>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
        </div>
        
        <div class="infobit-settings-section">
            <h2>⚙️ Opzioni Generali</h2>
            <table class="form-table">
                <tr>
                    <th>Aggiornamento automatico</th>
                    <td>
                        <label>
                            <input type="checkbox" name="osg_options[auto_regenerate]" value="1" <?php checked(!empty($options['auto_regenerate'])); ?>>
                            Aggiorna timestamp quando il contenuto viene modificato
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Ping Google</th>
                    <td>
                        <label>
                            <input type="checkbox" name="osg_options[ping_google_on_change]" value="1" <?php checked(!empty($options['ping_google_on_change'])); ?>>
                            Invia ping a Google quando i contenuti cambiano
                        </label>
                        <p class="description">Google ha deprecato il ping sitemap (giugno 2023), ma il plugin tenta comunque. Il metodo principale resta robots.txt + Search Console.</p>
                    </td>
                </tr>
                <tr>
                    <th>Max URL per file sitemap</th>
                    <td>
                        <input type="number" name="osg_options[max_urls_per_sitemap]" 
                               value="<?php echo esc_attr($options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP); ?>" 
                               min="1000" max="50000" step="1000" class="small-text">
                        <p class="description">Standard: max 50.000. Raccomandato: 10.000 per performance. Con <?php echo number_format_i18n($stats['products']); ?> prodotti = <?php echo ceil($stats['products'] / ($options['max_urls_per_sitemap'] ?? OSG_MAX_URLS_PER_SITEMAP)); ?> file sitemap prodotti.</p>
                    </td>
                </tr>
            </table>
        </div>
        
        <!-- Opzioni IndexNow -->
        <div class="infobit-settings-section">
            <h2>🚀 Opzioni IndexNow</h2>
            <table class="form-table">
                <tr>
                    <th>Abilita IndexNow</th>
                    <td>
                        <label>
                            <input type="checkbox" name="osg_indexnow_options[enabled]" value="1" <?php checked(!empty($indexnow_options['enabled'])); ?>>
                            Attiva notifiche a Bing/Yandex
                        </label>
                    </td>
                </tr>
                <tr>
                    <th>Invio automatico per</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="osg_indexnow_options[auto_submit_posts]" value="1" <?php checked(!empty($indexnow_options['auto_submit_posts'])); ?>>
                                Articoli
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_indexnow_options[auto_submit_pages]" value="1" <?php checked(!empty($indexnow_options['auto_submit_pages'])); ?>>
                                Pagine
                            </label><br>
                            <label>
                                <input type="checkbox" name="osg_indexnow_options[auto_submit_products]" value="1" <?php checked(!empty($indexnow_options['auto_submit_products'])); ?>>
                                Prodotti WooCommerce
                            </label>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th>Modalità invio</th>
                    <td>
                        <select name="osg_indexnow_options[queue_mode]">
                            <option value="immediate" <?php selected($indexnow_options['queue_mode'] ?? 'hourly', 'immediate'); ?>>
                                ⚡ Immediato (ogni modifica)
                            </option>
                            <option value="hourly" <?php selected($indexnow_options['queue_mode'] ?? 'hourly', 'hourly'); ?>>
                                🕐 Ogni ora (raccomandato)
                            </option>
                            <option value="twicedaily" <?php selected($indexnow_options['queue_mode'] ?? 'hourly', 'twicedaily'); ?>>
                                🕐 Ogni 12 ore
                            </option>
                            <option value="daily" <?php selected($indexnow_options['queue_mode'] ?? 'hourly', 'daily'); ?>>
                                🕐 Una volta al giorno
                            </option>
                        </select>
                        <p class="description">
                            <strong>Raccomandato: "Ogni ora"</strong> per e-commerce con aggiornamenti frequenti di magazzino.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Aggiornamenti magazzino</th>
                    <td>
                        <label>
                            <input type="checkbox" name="osg_indexnow_options[exclude_stock_updates]" value="1" <?php checked(!empty($indexnow_options['exclude_stock_updates'])); ?>>
                            <strong>Escludi aggiornamenti solo stock</strong>
                        </label>
                        <p class="description">
                            Non accodare quando cambiano solo quantità, stato stock.<br>
                            Accoda solo se cambiano: titolo, descrizione, prezzo, SKU.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Dimensione batch</th>
                    <td>
                        <input type="number" name="osg_indexnow_options[batch_size]" 
                               value="<?php echo esc_attr($indexnow_options['batch_size'] ?? 100); ?>" 
                               min="10" max="10000" step="10" class="small-text">
                        <span class="description">URL per invio (default: 100)</span>
                    </td>
                </tr>
                <tr>
                    <th>Endpoint</th>
                    <td>
                        <select name="osg_indexnow_options[endpoint]">
                            <option value="bing" <?php selected($indexnow_options['endpoint'] ?? 'bing', 'bing'); ?>>Bing (raccomandato)</option>
                            <option value="yandex" <?php selected($indexnow_options['endpoint'] ?? 'bing', 'yandex'); ?>>Yandex</option>
                        </select>
                        <p class="description">L'endpoint condivide i dati con gli altri motori IndexNow.</p>
                    </td>
                </tr>
                <tr>
                    <th>Log attività</th>
                    <td>
                        <label>
                            <input type="checkbox" name="osg_indexnow_options[log_enabled]" value="1" <?php checked(!empty($indexnow_options['log_enabled'])); ?>>
                            Registra attività IndexNow
                        </label>
                    </td>
                </tr>
            </table>
        </div>
        
        <?php submit_button('Salva Impostazioni'); ?>
    </form>
    
    <!-- Info -->
    <div class="infobit-info-box">
        <h2>📋 Riepilogo Notifiche Motori di Ricerca</h2>
        <table class="widefat" style="max-width:600px;">
            <thead>
                <tr>
                    <th>Motore</th>
                    <th>Metodo</th>
                    <th>Stato</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Google</strong></td>
                    <td>robots.txt + Search Console</td>
                    <td>✅ Automatico (robots.txt)</td>
                </tr>
                <tr>
                    <td><strong>Bing</strong></td>
                    <td>IndexNow (coda temporizzata)</td>
                    <td><?php echo !empty($indexnow_options['enabled']) ? '✅ Attivo' : '❌ Disabilitato'; ?></td>
                </tr>
                <tr>
                    <td><strong>Yandex</strong></td>
                    <td>IndexNow (condiviso da Bing)</td>
                    <td><?php echo !empty($indexnow_options['enabled']) ? '✅ Attivo' : '❌ Disabilitato'; ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>
