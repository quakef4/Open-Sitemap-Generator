=== Open Sitemap Generator ===
Contributors: quakef4
Tags: sitemap, xml sitemap, seo, woocommerce, indexnow, bing, google, yandex, rich results, structured data
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dynamic XML sitemap generator with automatic pagination, IndexNow queue, Google Rich Results for WooCommerce, and Google/Bing/Yandex notification. Optimized for large WooCommerce stores.

== Description ==

**Open Sitemap Generator** is built for WooCommerce stores with tens of thousands of products.

= Key Features =

* **Automatic pagination** — Splits sitemaps into files of 10,000 URLs (configurable up to 50,000)
* **Optimized DB queries** — Direct SQL queries, never loads 40,000+ objects into memory
* **Google Rich Results** — Enhances product schema with shipping, return policy, reviews, and seller for better search visibility
* **IndexNow queued mode** — Batched notifications, never blocks stock sync loops
* **Stock update filtering** — Only notifies when meaningful content changes (not just quantity)
* **Google notification** — Automatic robots.txt + optional ping
* **Bing/Yandex notification** — Instant via IndexNow protocol

= Google Rich Results =

The Rich Results module adds structured data (JSON-LD) to WooCommerce products:

* **shippingDetails** — Shipping cost from WooCommerce zones, destination, handling + transit times
* **hasMerchantReturnPolicy** — Return days, fees, shipping cost, policy URL
* **aggregateRating + review** — Product ratings and individual reviews
* **seller** — Merchant name/URL inside offers
* **Product name truncation** — Respects Google's recommended max length
* **Built-in self-test** — Validates schema on a real product from admin dashboard

= Search Engine Support =

| Engine | Method | Note |
|--------|--------|------|
| Google | robots.txt + Search Console + Rich Results | Ping deprecated but attempted |
| Bing   | IndexNow (queued) | Instant notification |
| Yandex | IndexNow (shared) | Instant notification |

== Installation ==

1. Upload `open-sitemap-generator` to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **Settings > Permalinks** and click **Save**
4. Go to **Settings > Open Sitemap** and configure

= Recommended E-commerce Settings =

* Queue mode: **Hourly**
* Exclude stock-only updates: **Enabled**
* Max URLs per file: **10,000**
* Rich Results: **Enabled**
* Merchant name: **Your store name**
* Return days: **14** (EU standard)

== Changelog ==

= 1.4.0 =
* NEW: Google Rich Results module for WooCommerce product schema enhancement
* NEW: shippingDetails — reads shipping cost from WooCommerce zones (flat rate + free shipping)
* NEW: hasMerchantReturnPolicy — configurable return days, fees, returnShippingFeesAmount
* NEW: aggregateRating + review — product ratings and reviews in schema (with WC comment type fallback)
* NEW: seller (Organization) inside offers
* NEW: Product name truncation (configurable, default 150 chars)
* NEW: Built-in self-test validates schema generation on real products
* NEW: Full admin settings section for Rich Results configuration
* FIX: hasMerchantReturnPolicy referenced in offers when return_days = 0
* FIX: Review datePublished uses wp_date() for timezone consistency
* FIX: WooCommerce review type fallback for older versions
* FIX: Empty reviewBody omitted instead of blank string
* FIX: Empty author name falls back to "Anonimo"
* FIX: aggregateRating calculated from found reviews as fallback

= 1.3.0 =
* Rebranded to "Open Sitemap Generator" for public distribution
* All internal prefixes standardized to `osg_`
* Added CHANGELOG.md, README.md, LICENSE
* Prepared for GitHub repository

= 1.2.1 =
* Fixed timezone bug: all admin times now use WordPress timezone (wp_date)
* Fixed daily quota reset at local midnight instead of UTC

= 1.2.0 =
* NEW: Automatic sitemap pagination (10,000 URLs/file)
* NEW: Direct DB queries (no memory leak with 40,000+ products)
* NEW: IndexNow queued mode with configurable intervals
* NEW: Stock-only update exclusion (real field comparison)
* NEW: Google ping + robots.txt integration
* NEW: Queue dashboard with process/clear buttons
* FIX: Duplicate URL queueing (per-request deduplication)
* FIX: Queue saved with autoload=no
* FIX: wp_count_terms compatible with WP 6.0+
* FIX: Consistent IndexNow quota tracking

= 1.1.0 =
* Added IndexNow protocol support for Bing/Yandex
* Auto-submit on content changes
* Bulk submit all URLs
* Daily quota tracking with activity log

= 1.0.0 =
* Initial release
* Dynamic XML sitemap for WordPress/WooCommerce
* Admin settings page with statistics
