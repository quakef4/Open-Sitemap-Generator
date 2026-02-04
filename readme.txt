=== Open Sitemap Generator ===
Contributors: infobitsnc
Tags: sitemap, xml sitemap, seo, woocommerce, indexnow, bing, google, yandex
Requires at least: 5.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dynamic XML sitemap generator with automatic pagination, IndexNow queue, and Google/Bing/Yandex notification. Optimized for large WooCommerce stores.

== Description ==

**Open Sitemap Generator** is built for WooCommerce stores with tens of thousands of products.

= Key Features =

* **Automatic pagination** — Splits sitemaps into files of 10,000 URLs (configurable up to 50,000)
* **Optimized DB queries** — Direct SQL queries, never loads 40,000+ objects into memory
* **IndexNow queued mode** — Batched notifications, never blocks stock sync loops
* **Stock update filtering** — Only notifies when meaningful content changes (not just quantity)
* **Google notification** — Automatic robots.txt + optional ping
* **Bing/Yandex notification** — Instant via IndexNow protocol

= Search Engine Support =

| Engine | Method | Note |
|--------|--------|------|
| Google | robots.txt + Search Console | Ping deprecated but attempted |
| Bing   | IndexNow (queued) | Instant notification |
| Yandex | IndexNow (shared) | Instant notification |

== Installation ==

1. Upload `open-sitemap-generator` to `/wp-content/plugins/`
2. Activate the plugin
3. Go to **Settings → Permalinks** and click **Save**
4. Go to **Settings → Open Sitemap** and configure

= Recommended E-commerce Settings =

* Queue mode: **Hourly**
* Exclude stock-only updates: **Enabled**
* Max URLs per file: **10,000**

== Changelog ==

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
