<?php
/**
 * Rich Results / Schema Enhancement per Open Sitemap Generator
 *
 * Aggiunge dati strutturati avanzati ai prodotti WooCommerce per Google Rich Results:
 * - shippingDetails (OfferShippingDetails)
 * - hasMerchantReturnPolicy (MerchantReturnPolicy)
 * - aggregateRating e review
 * - seller (Organization) dentro le offerte
 * - Troncamento nome prodotto per rispettare i limiti Google
 *
 * @package Open_Sitemap_Generator
 * @since 1.4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OSG_Rich_Results {

    private static $instance = null;
    private $options;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option('osg_rich_results_options', $this->get_default_options());

        if (!empty($this->options['enabled'])) {
            add_filter('woocommerce_structured_data_product', array($this, 'enhance_product_schema'), 99, 2);
        }

        // Admin
        add_action('admin_init', array($this, 'register_settings'));

        // AJAX self-test
        add_action('wp_ajax_osg_rich_results_test', array($this, 'ajax_self_test'));
    }

    // =============================================================
    //  DEFAULT OPTIONS
    // =============================================================

    private function get_default_options() {
        return array(
            'enabled'            => false,
            'return_days'        => 14,
            'return_policy_url'  => '',
            'return_fees'        => 'https://schema.org/FreeReturn',
            'return_shipping_fees_amount' => 0,
            'merchant_name'      => '',
            'merchant_url'       => '',
            'max_name_length'    => 150,
            'shipping_country'   => 'IT',
            'handling_days_min'  => 0,
            'handling_days_max'  => 1,
            'transit_days_min'   => 1,
            'transit_days_max'   => 3,
            'include_reviews'    => true,
            'max_reviews'        => 5,
        );
    }

    // =============================================================
    //  MAIN FILTER: ENHANCE PRODUCT SCHEMA
    // =============================================================

    /**
     * Arricchisce il markup JSON-LD del prodotto WooCommerce.
     *
     * @param array      $markup  Il markup prodotto esistente.
     * @param WC_Product $product L'oggetto prodotto WooCommerce.
     * @return array Markup arricchito.
     */
    public function enhance_product_schema($markup, $product) {

        $config = $this->options;

        // --- Tronca nome prodotto se troppo lungo ---
        $max_len = intval($config['max_name_length']);
        if ($max_len > 0 && isset($markup['name']) && mb_strlen($markup['name']) > $max_len) {
            $markup['name'] = mb_substr($markup['name'], 0, $max_len - 3) . '...';
        }

        // --- Shipping Details ---
        $shipping_data = $this->get_wc_shipping_for_product($product);

        $markup['shippingDetails'] = array(
            '@type' => 'OfferShippingDetails',
            'shippingRate' => array(
                '@type'    => 'MonetaryAmount',
                'value'    => $shipping_data['cost'],
                'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR',
            ),
            'shippingDestination' => array(
                '@type'          => 'DefinedRegion',
                'addressCountry' => $shipping_data['country'],
            ),
            'deliveryTime' => array(
                '@type'        => 'ShippingDeliveryTime',
                'handlingTime' => array(
                    '@type'    => 'QuantitativeValue',
                    'minValue' => intval($config['handling_days_min']),
                    'maxValue' => intval($config['handling_days_max']),
                    'unitCode' => 'd',
                ),
                'transitTime' => array(
                    '@type'    => 'QuantitativeValue',
                    'minValue' => $shipping_data['days_min'],
                    'maxValue' => $shipping_data['days_max'],
                    'unitCode' => 'd',
                ),
            ),
        );

        // --- Merchant Return Policy ---
        $return_days = intval($config['return_days']);
        if ($return_days > 0) {
            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'EUR';
            $return_fees_type = !empty($config['return_fees']) ? $config['return_fees'] : 'https://schema.org/FreeReturn';
            $return_policy = array(
                '@type'                => 'MerchantReturnPolicy',
                'applicableCountry'    => $shipping_data['country'],
                'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
                'merchantReturnDays'   => $return_days,
                'returnMethod'         => 'https://schema.org/ReturnByMail',
                'returnFees'           => $return_fees_type,
            );

            // returnShippingFeesAmount solo quando il reso NON e gratuito
            // Google segnala "Valore non valido" se presente con FreeReturn
            if ($return_fees_type !== 'https://schema.org/FreeReturn') {
                $fees_amount = floatval($config['return_shipping_fees_amount'] ?? 0);
                $return_policy['returnShippingFeesAmount'] = array(
                    '@type'    => 'MonetaryAmount',
                    'value'    => $fees_amount,
                    'currency' => $currency,
                );
            }
            if (!empty($config['return_policy_url'])) {
                $return_policy['url'] = $config['return_policy_url'];
            }
            $markup['hasMerchantReturnPolicy'] = $return_policy;
        }

        // --- Aggregate Rating & Reviews ---
        if (!empty($config['include_reviews'])) {
            $max_reviews = intval($config['max_reviews']);
            $product_id  = $product->get_id();

            // WooCommerce cached values (possono essere 0 se le recensioni sono state aggiunte manualmente)
            $wc_rating_count   = $product->get_rating_count();
            $wc_average_rating = floatval($product->get_average_rating());

            // Cerca recensioni direttamente nel DB — non affidarsi solo al contatore WC cached
            // Prima tipo 'review' (WC 3.x+), poi fallback a commenti con meta rating
            $reviews = get_comments(array(
                'post_id' => $product_id,
                'status'  => 'approve',
                'type'    => 'review',
                'number'  => $max_reviews,
            ));

            if (empty($reviews)) {
                $reviews = get_comments(array(
                    'post_id'  => $product_id,
                    'status'   => 'approve',
                    'type'     => '',
                    'meta_key' => 'rating',
                    'number'   => $max_reviews,
                ));
            }

            // Se non trovate ancora, cerca TUTTI i commenti approvati del prodotto (recensione senza meta rating)
            if (empty($reviews)) {
                $reviews = get_comments(array(
                    'post_id' => $product_id,
                    'status'  => 'approve',
                    'number'  => $max_reviews,
                ));
                // Filtra: tieni solo quelli che hanno contenuto (non pingback/trackback)
                $reviews = array_filter($reviews, function ($c) {
                    return !empty($c->comment_content) && !in_array($c->comment_type, array('pingback', 'trackback'), true);
                });
                $reviews = array_values($reviews);
            }

            // Se WC ha i contatori cached, usali per aggregateRating
            if ($wc_rating_count > 0 && $wc_average_rating > 0) {
                $markup['aggregateRating'] = array(
                    '@type'       => 'AggregateRating',
                    'ratingValue' => $wc_average_rating,
                    'reviewCount' => $wc_rating_count,
                    'bestRating'  => '5',
                    'worstRating' => '1',
                );
            }

            // review: aggiunge solo se ci sono recensioni reali trovate
            if (!empty($reviews)) {
                $markup['review'] = array();
                foreach ($reviews as $review) {
                    $rating = get_comment_meta($review->comment_ID, 'rating', true);
                    if (!$rating || intval($rating) < 1) {
                        $rating = 5;
                    }
                    $author_name = !empty($review->comment_author)
                        ? $review->comment_author
                        : __('Anonimo', 'open-sitemap-generator');
                    $review_body = wp_strip_all_tags($review->comment_content);

                    $review_item = array(
                        '@type'        => 'Review',
                        'reviewRating' => array(
                            '@type'       => 'Rating',
                            'ratingValue' => intval($rating),
                            'bestRating'  => '5',
                            'worstRating' => '1',
                        ),
                        'author' => array(
                            '@type' => 'Person',
                            'name'  => $author_name,
                        ),
                        'datePublished' => wp_date('Y-m-d', strtotime($review->comment_date)),
                    );

                    // reviewBody solo se presente (Google lo accetta vuoto ma e meglio ometterlo)
                    if (!empty($review_body)) {
                        $review_item['reviewBody'] = $review_body;
                    }

                    $markup['review'][] = $review_item;
                }

                // Se abbiamo review ma non aggregateRating (WC cache vuota), calcolalo dalle review trovate
                if (!isset($markup['aggregateRating']) && !empty($markup['review'])) {
                    $total = 0;
                    $count = count($markup['review']);
                    foreach ($markup['review'] as $r) {
                        $total += intval($r['reviewRating']['ratingValue']);
                    }
                    $markup['aggregateRating'] = array(
                        '@type'       => 'AggregateRating',
                        'ratingValue' => round($total / $count, 1),
                        'reviewCount' => $count,
                        'bestRating'  => '5',
                        'worstRating' => '1',
                    );
                }
            }
        }

        // --- Seller (inside offers) ---
        if (isset($markup['offers']) && !empty($config['merchant_name'])) {
            $seller = array(
                '@type' => 'Organization',
                'name'  => $config['merchant_name'],
            );
            if (!empty($config['merchant_url'])) {
                $seller['url'] = $config['merchant_url'];
            }

            $offer_extras = array(
                'seller'           => $seller,
                'shippingDetails'  => $markup['shippingDetails'],
            );
            if (isset($markup['hasMerchantReturnPolicy'])) {
                $offer_extras['hasMerchantReturnPolicy'] = $markup['hasMerchantReturnPolicy'];
            }

            if (isset($markup['offers']['@type'])) {
                // Single offer
                $markup['offers'] = array_merge($markup['offers'], $offer_extras);
            } else {
                // Array of offers
                foreach ($markup['offers'] as &$offer) {
                    if (is_array($offer)) {
                        $offer = array_merge($offer, $offer_extras);
                    }
                }
                unset($offer);
            }
        }

        return $markup;
    }

    // =============================================================
    //  SHIPPING DATA FROM WOOCOMMERCE
    // =============================================================

    /**
     * Legge le impostazioni di spedizione da WooCommerce per il prodotto dato.
     *
     * @param WC_Product $product Il prodotto WooCommerce.
     * @return array Array con cost, country, days_min, days_max.
     */
    public function get_wc_shipping_for_product($product) {

        $config = $this->options;
        $target_country = !empty($config['shipping_country']) ? $config['shipping_country'] : 'IT';

        $defaults = array(
            'cost'     => 0,
            'country'  => $target_country,
            'days_min' => intval($config['transit_days_min']),
            'days_max' => intval($config['transit_days_max']),
        );

        if (!class_exists('WC_Shipping_Zones')) {
            return $defaults;
        }

        $product_price     = floatval($product->get_price());
        $shipping_class_id = $product->get_shipping_class_id();

        $zones    = WC_Shipping_Zones::get_zones();
        $zone_obj = WC_Shipping_Zones::get_zone(0);
        $zones[0] = array(
            'zone_id'          => 0,
            'shipping_methods' => $zone_obj ? $zone_obj->get_shipping_methods(true) : array(),
        );

        $target_zone = null;

        // Cerca zona per il paese configurato
        foreach ($zones as $zone_data) {
            if (intval($zone_data['zone_id']) === 0) {
                continue; // skip "Rest of the World" in prima passata
            }
            $zone      = new WC_Shipping_Zone($zone_data['zone_id']);
            $locations = $zone->get_zone_locations();
            foreach ($locations as $location) {
                if ($location->type === 'country' && $location->code === $target_country) {
                    $target_zone = $zone;
                    break 2;
                }
            }
        }

        // Fallback: prima zona con metodi attivi
        if (!$target_zone) {
            foreach ($zones as $zone_data) {
                $zone = new WC_Shipping_Zone($zone_data['zone_id']);
                if (!empty($zone->get_shipping_methods(true))) {
                    $target_zone = $zone;
                    break;
                }
            }
        }

        if (!$target_zone) {
            return $defaults;
        }

        $methods         = $target_zone->get_shipping_methods(true);
        $flat_rate_cost  = null;
        $free_shipping_min = null;

        foreach ($methods as $method) {
            if ($method->id === 'free_shipping') {
                $min_amount = floatval($method->get_option('min_amount', 0));
                $requires   = $method->get_option('requires', '');
                if ($requires === '' || $requires === 'min_amount') {
                    $free_shipping_min = $min_amount;
                }
                if ($requires === '' && $min_amount == 0) {
                    return array_merge($defaults, array('cost' => 0));
                }
            }

            if ($method->id === 'flat_rate') {
                $cost_string = $method->get_option('cost', '0');
                $base_cost   = floatval(preg_replace('/[^0-9.]/', '', $cost_string));

                if ($shipping_class_id) {
                    $class_cost = $method->get_option('class_cost_' . $shipping_class_id, '');
                    if ($class_cost !== '') {
                        $base_cost = floatval(preg_replace('/[^0-9.]/', '', $class_cost));
                    }
                }

                if ($flat_rate_cost === null || $base_cost < $flat_rate_cost) {
                    $flat_rate_cost = $base_cost;
                }
            }
        }

        $final_cost = $flat_rate_cost !== null ? $flat_rate_cost : 0;

        if ($free_shipping_min !== null && $product_price >= $free_shipping_min) {
            $final_cost = 0;
        }

        return array(
            'cost'     => $final_cost,
            'country'  => $target_country,
            'days_min' => intval($config['transit_days_min']),
            'days_max' => intval($config['transit_days_max']),
        );
    }

    // =============================================================
    //  SELF-TEST
    // =============================================================

    /**
     * Esegue un self-test interno che verifica la struttura dello schema generato.
     * Utilizzabile via AJAX dall'admin o direttamente.
     *
     * @return array Array con 'success' (bool) e 'results' (array di test).
     */
    public function run_self_test() {
        $results = array();

        // Test 1: Verifica che WooCommerce sia attivo
        $results[] = array(
            'name'   => 'WooCommerce attivo',
            'pass'   => class_exists('WooCommerce'),
            'detail' => class_exists('WooCommerce')
                ? 'WooCommerce rilevato'
                : 'WooCommerce non attivo - il modulo Rich Results richiede WooCommerce',
        );

        // Test 2: Verifica configurazione obbligatoria
        $has_merchant = !empty($this->options['merchant_name']);
        $results[] = array(
            'name'   => 'Nome commerciante configurato',
            'pass'   => $has_merchant,
            'detail' => $has_merchant
                ? 'merchant_name: ' . $this->options['merchant_name']
                : 'Inserisci il nome del commerciante nelle impostazioni',
        );

        // Test 3: Verifica URL policy reso (opzionale ma raccomandato)
        $has_return_url = !empty($this->options['return_policy_url']);
        $results[] = array(
            'name'   => 'URL politica reso configurato',
            'pass'   => $has_return_url,
            'detail' => $has_return_url
                ? $this->options['return_policy_url']
                : 'Raccomandato: inserisci URL della pagina politica reso',
        );

        // Test 4: Simula generazione schema su un prodotto reale
        $schema_test_result = $this->test_schema_generation();
        $results[] = $schema_test_result;

        // Test 5: Valida la struttura dello schema generato
        if ($schema_test_result['pass'] && isset($schema_test_result['_markup'])) {
            $validation_results = $this->validate_schema_structure($schema_test_result['_markup']);
            $results = array_merge($results, $validation_results);
        }

        // Test 6: Verifica filtro WooCommerce
        $filter_attached = has_filter('woocommerce_structured_data_product', array($this, 'enhance_product_schema'));
        $results[] = array(
            'name'   => 'Filtro WooCommerce attivo',
            'pass'   => $filter_attached !== false,
            'detail' => $filter_attached !== false
                ? 'enhance_product_schema agganciato con priorita ' . $filter_attached
                : 'Il filtro non e agganciato. Verifica che Rich Results sia abilitato.',
        );

        $all_pass = true;
        foreach ($results as $r) {
            if (empty($r['pass'])) {
                $all_pass = false;
                break;
            }
        }

        // Remove internal data from results
        $clean_results = array_map(function ($r) {
            unset($r['_markup']);
            return $r;
        }, $results);

        return array(
            'success' => $all_pass,
            'results' => $clean_results,
        );
    }

    /**
     * Testa la generazione dello schema su un prodotto reale pubblicato.
     * Preferisce un prodotto CON recensioni per un test piu completo.
     */
    private function test_schema_generation() {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_products')) {
            return array(
                'name'   => 'Generazione schema prodotto',
                'pass'   => false,
                'detail' => 'WooCommerce non disponibile per il test',
            );
        }

        $product = null;

        // Prima cerca un prodotto con recensioni/commenti per un test piu completo
        global $wpdb;
        $product_with_reviews_id = $wpdb->get_var(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->comments} c ON c.comment_post_ID = p.ID AND c.comment_approved = '1'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             ORDER BY c.comment_date DESC
             LIMIT 1"
        );

        if ($product_with_reviews_id) {
            $product = wc_get_product($product_with_reviews_id);
        }

        // Fallback: ultimo prodotto pubblicato
        if (!$product) {
            $products = wc_get_products(array(
                'status'  => 'publish',
                'limit'   => 1,
                'orderby' => 'date',
                'order'   => 'DESC',
            ));
            if (!empty($products)) {
                $product = $products[0];
            }
        }

        if (!$product) {
            return array(
                'name'   => 'Generazione schema prodotto',
                'pass'   => false,
                'detail' => 'Nessun prodotto pubblicato trovato per il test',
            );
        }

        // Simula markup base come lo genererebbe WooCommerce
        $mock_markup = array(
            '@type' => 'Product',
            'name'  => $product->get_name(),
            'url'   => $product->get_permalink(),
            'offers' => array(
                '@type'         => 'Offer',
                'price'         => $product->get_price(),
                'priceCurrency' => get_woocommerce_currency(),
                'availability'  => $product->is_in_stock()
                    ? 'https://schema.org/InStock'
                    : 'https://schema.org/OutOfStock',
            ),
        );

        $enhanced = $this->enhance_product_schema($mock_markup, $product);

        $has_shipping = isset($enhanced['shippingDetails']);
        $has_return   = isset($enhanced['hasMerchantReturnPolicy']);

        return array(
            'name'    => 'Generazione schema prodotto',
            'pass'    => $has_shipping,
            'detail'  => sprintf(
                'Prodotto "%s" (ID %d) - shippingDetails: %s, returnPolicy: %s',
                mb_substr($product->get_name(), 0, 40),
                $product->get_id(),
                $has_shipping ? 'OK' : 'MANCANTE',
                $has_return ? 'OK' : 'MANCANTE (return_days = 0?)'
            ),
            '_markup' => $enhanced,
        );
    }

    /**
     * Valida la struttura dello schema generato rispetto ai requisiti Google.
     */
    private function validate_schema_structure($markup) {
        $results = array();

        // shippingDetails structure
        if (isset($markup['shippingDetails'])) {
            $sd = $markup['shippingDetails'];

            $has_rate = isset($sd['shippingRate']['@type'])
                && $sd['shippingRate']['@type'] === 'MonetaryAmount'
                && isset($sd['shippingRate']['value'])
                && isset($sd['shippingRate']['currency']);
            $results[] = array(
                'name'   => 'Schema: shippingRate valido',
                'pass'   => $has_rate,
                'detail' => $has_rate
                    ? sprintf('Costo: %s %s', $sd['shippingRate']['value'], $sd['shippingRate']['currency'])
                    : 'shippingRate incompleto o malformato',
            );

            $has_dest = isset($sd['shippingDestination']['addressCountry']);
            $results[] = array(
                'name'   => 'Schema: shippingDestination valido',
                'pass'   => $has_dest,
                'detail' => $has_dest
                    ? 'Paese: ' . $sd['shippingDestination']['addressCountry']
                    : 'shippingDestination.addressCountry mancante',
            );

            $has_delivery = isset($sd['deliveryTime']['transitTime']['minValue'])
                && isset($sd['deliveryTime']['transitTime']['maxValue']);
            $results[] = array(
                'name'   => 'Schema: deliveryTime valido',
                'pass'   => $has_delivery,
                'detail' => $has_delivery
                    ? sprintf(
                        'Transito: %d-%d giorni',
                        $sd['deliveryTime']['transitTime']['minValue'],
                        $sd['deliveryTime']['transitTime']['maxValue']
                    )
                    : 'deliveryTime.transitTime incompleto',
            );
        }

        // hasMerchantReturnPolicy structure
        if (isset($markup['hasMerchantReturnPolicy'])) {
            $rp = $markup['hasMerchantReturnPolicy'];

            $has_required = isset($rp['@type'])
                && $rp['@type'] === 'MerchantReturnPolicy'
                && isset($rp['returnPolicyCategory'])
                && isset($rp['merchantReturnDays'])
                && isset($rp['applicableCountry']);
            $results[] = array(
                'name'   => 'Schema: returnPolicy valido',
                'pass'   => $has_required,
                'detail' => $has_required
                    ? sprintf('Reso entro %d giorni, paese: %s', $rp['merchantReturnDays'], $rp['applicableCountry'])
                    : 'MerchantReturnPolicy incompleto',
            );
        }

        // returnShippingFeesAmount check
        if (isset($markup['hasMerchantReturnPolicy'])) {
            $rp_fees = $markup['hasMerchantReturnPolicy']['returnFees'] ?? '';
            $is_free = ($rp_fees === 'https://schema.org/FreeReturn');
            $has_fees_amount = isset($markup['hasMerchantReturnPolicy']['returnShippingFeesAmount']['@type'])
                && $markup['hasMerchantReturnPolicy']['returnShippingFeesAmount']['@type'] === 'MonetaryAmount';

            if ($is_free) {
                // Per FreeReturn, returnShippingFeesAmount NON deve essere presente
                $pass = !$has_fees_amount;
                $results[] = array(
                    'name'   => 'Schema: returnShippingFeesAmount (FreeReturn)',
                    'pass'   => $pass,
                    'detail' => $pass
                        ? 'Reso gratuito: returnShippingFeesAmount correttamente omesso'
                        : 'Reso gratuito ma returnShippingFeesAmount presente (Google segnala valore non valido)',
                );
            } else {
                // Per altri tipi di reso, returnShippingFeesAmount deve essere presente
                $results[] = array(
                    'name'   => 'Schema: returnShippingFeesAmount valido',
                    'pass'   => $has_fees_amount,
                    'detail' => $has_fees_amount
                        ? sprintf(
                            'Costo reso: %s %s',
                            $markup['hasMerchantReturnPolicy']['returnShippingFeesAmount']['value'],
                            $markup['hasMerchantReturnPolicy']['returnShippingFeesAmount']['currency']
                        )
                        : 'returnShippingFeesAmount mancante (richiesto per reso non gratuito)',
                );
            }
        }

        // aggregateRating & review check
        $has_rating = isset($markup['aggregateRating']['@type'])
            && $markup['aggregateRating']['@type'] === 'AggregateRating';
        $has_reviews = isset($markup['review']) && is_array($markup['review']) && count($markup['review']) > 0;
        $include_reviews = !empty($this->options['include_reviews']);

        if ($has_rating && $has_reviews) {
            $results[] = array(
                'name'   => 'Schema: aggregateRating e review',
                'pass'   => true,
                'detail' => sprintf(
                    'Rating: %s/5 (%d recensioni), %d review nello schema',
                    $markup['aggregateRating']['ratingValue'],
                    $markup['aggregateRating']['reviewCount'],
                    count($markup['review'])
                ),
            );
        } elseif ($has_rating && !$has_reviews) {
            $results[] = array(
                'name'   => 'Schema: aggregateRating senza review',
                'pass'   => true,
                'detail' => sprintf(
                    'Rating: %s/5 (%d recensioni) - review non trovate nel DB ma aggregateRating presente',
                    $markup['aggregateRating']['ratingValue'],
                    $markup['aggregateRating']['reviewCount']
                ),
            );
        } else {
            $results[] = array(
                'name'   => 'Schema: aggregateRating e review',
                'pass'   => true, // pass=true perche e facoltativo, non un errore
                'detail' => !$include_reviews
                    ? 'Recensioni disabilitate nelle impostazioni Rich Results'
                    : 'Nessuna recensione per questo prodotto (facoltativo per Google, appare come avviso giallo)',
            );
        }

        // Offers should contain seller + shipping + return
        if (isset($markup['offers'])) {
            $offer = isset($markup['offers']['@type']) ? $markup['offers'] : (isset($markup['offers'][0]) ? $markup['offers'][0] : null);
            if ($offer) {
                $has_seller = isset($offer['seller']['@type']) && $offer['seller']['@type'] === 'Organization';
                $results[] = array(
                    'name'   => 'Schema: seller in offers',
                    'pass'   => $has_seller,
                    'detail' => $has_seller
                        ? 'seller.name: ' . ($offer['seller']['name'] ?? 'N/A')
                        : 'seller mancante nelle offers (configura il nome commerciante)',
                );

                $has_offer_shipping = isset($offer['shippingDetails']);
                $results[] = array(
                    'name'   => 'Schema: shippingDetails in offers',
                    'pass'   => $has_offer_shipping,
                    'detail' => $has_offer_shipping
                        ? 'shippingDetails presente anche dentro offers'
                        : 'shippingDetails mancante dentro offers',
                );
            }
        }

        return $results;
    }

    // =============================================================
    //  AJAX SELF-TEST
    // =============================================================

    public function ajax_self_test() {
        check_ajax_referer('osg_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permesso negato');
        }

        $test = $this->run_self_test();
        wp_send_json_success($test);
    }

    // =============================================================
    //  ADMIN SETTINGS
    // =============================================================

    public function register_settings() {
        register_setting('osg_settings_group', 'osg_rich_results_options', array($this, 'sanitize_options'));
    }

    public function sanitize_options($input) {
        $s = array();

        $s['enabled']           = !empty($input['enabled']);
        $s['include_reviews']   = !empty($input['include_reviews']);
        $s['return_days']       = max(0, absint($input['return_days'] ?? 14));
        $s['return_policy_url'] = esc_url_raw($input['return_policy_url'] ?? '');
        $s['return_fees']       = sanitize_text_field($input['return_fees'] ?? 'https://schema.org/FreeReturn');
        $s['return_shipping_fees_amount'] = max(0, floatval($input['return_shipping_fees_amount'] ?? 0));
        $s['merchant_name']     = sanitize_text_field($input['merchant_name'] ?? '');
        $s['merchant_url']      = esc_url_raw($input['merchant_url'] ?? '');
        $s['max_name_length']   = max(50, min(500, absint($input['max_name_length'] ?? 150)));
        $s['shipping_country']  = sanitize_text_field($input['shipping_country'] ?? 'IT');
        $s['handling_days_min'] = max(0, absint($input['handling_days_min'] ?? 0));
        $s['handling_days_max'] = max(0, absint($input['handling_days_max'] ?? 1));
        $s['transit_days_min']  = max(0, absint($input['transit_days_min'] ?? 1));
        $s['transit_days_max']  = max(0, absint($input['transit_days_max'] ?? 3));
        $s['max_reviews']       = max(1, min(20, absint($input['max_reviews'] ?? 5)));

        return $s;
    }

    // =============================================================
    //  GETTERS PER ADMIN
    // =============================================================

    public function get_options() {
        return $this->options;
    }

    public function get_stats() {
        $is_active = !empty($this->options['enabled'])
            && class_exists('WooCommerce')
            && has_filter('woocommerce_structured_data_product', array($this, 'enhance_product_schema'));

        return array(
            'enabled'         => !empty($this->options['enabled']),
            'active'          => $is_active,
            'merchant_name'   => $this->options['merchant_name'] ?? '',
            'shipping_country'=> $this->options['shipping_country'] ?? 'IT',
            'return_days'     => $this->options['return_days'] ?? 14,
        );
    }
}

// Inizializza
add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce') || is_admin()) {
        OSG_Rich_Results::get_instance();
    }
}, 25);
