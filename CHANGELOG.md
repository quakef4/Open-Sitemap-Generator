# Changelog

All notable changes to **Open Sitemap Generator** are documented in this file.

Format based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
versioned with [Semantic Versioning](https://semver.org/).

---

## [1.4.0] - 2026-02-10

### Added
- **Google Rich Results module** (`includes/class-rich-results.php`) — Enhances WooCommerce product JSON-LD with structured data for Google Rich Results
- **shippingDetails** (OfferShippingDetails) — Reads shipping cost from WooCommerce shipping zones (flat rate + free shipping with min amount), configurable delivery times
- **hasMerchantReturnPolicy** (MerchantReturnPolicy) — Configurable return days, return fees type, return policy URL, `returnShippingFeesAmount`
- **aggregateRating + review** — Includes product ratings and up to N reviews in schema, with fallback for older WooCommerce comment types
- **seller** (Organization) — Adds merchant name/URL inside offers
- **Product name truncation** — Truncates names exceeding configurable max length (default 150 chars, Google recommendation)
- **Built-in self-test** — Admin button "Esegui Test Rich Results" validates schema generation on a real product, checks all required fields
- **Full admin settings section** — Merchant info, shipping country, handling/transit times, return policy, review settings, all configurable from dashboard
- **returnShippingFeesAmount** — MonetaryAmount field in return policy to eliminate Google validator warning

### Fixed
- **BUG: `hasMerchantReturnPolicy` referenced in offers when not set** — Original code accessed return policy in offers section even when `return_days = 0`. Added `isset()` check
- **BUG: `date()` instead of `wp_date()`** — Review `datePublished` now uses `wp_date()` for timezone consistency
- **BUG: WooCommerce review type fallback** — Some WooCommerce versions store reviews as regular comments with `rating` meta instead of `type=review`. Added fallback query
- **BUG: Empty `reviewBody` in schema** — Now omitted when empty instead of outputting blank string
- **BUG: Empty `comment_author`** — Falls back to "Anonimo" instead of blank author name
- **BUG: `aggregateRating` without `review` entries** — If reviews exist in DB but `aggregateRating` was missing from WooCommerce, it's now calculated from found reviews

### Changed
- Plugin version bumped to 1.4.0
- Plugin description updated to mention Rich Results
- Admin JavaScript updated with self-test handler and HTML escaping helper
- Settings page reorganized with Rich Results status section and settings form

### Technical notes
- Rich Results module follows existing singleton pattern (`OSG_Rich_Results`)
- Hooks into `woocommerce_structured_data_product` filter at priority 99
- Settings stored in `osg_rich_results_options` (separate from main plugin options)
- Module initializes on `plugins_loaded` at priority 25 (after WooCommerce)
- Self-test validates: WooCommerce active, merchant configured, return policy URL, schema generation, shippingRate, shippingDestination, deliveryTime, returnPolicy, returnShippingFeesAmount, aggregateRating/review, seller in offers

---

## [1.3.0] - 2026-02-04

### Changed
- **REBRANDING**: Renamed from "Infobit Sitemap Generator" to "Open Sitemap Generator"
- All internal prefixes changed from `infobit_` to `osg_` for namespace clarity
- Class names updated: `Infobit_Sitemap_Generator` → `Open_Sitemap_Generator`, `Infobit_IndexNow` → `OSG_IndexNow`
- Option keys renamed: `infobit_sitemap_options` → `osg_options`, `infobit_indexnow_*` → `osg_indexnow_*`
- Plugin prepared for public GitHub distribution

### Added
- `CHANGELOG.md` with full version history
- `README.md` for GitHub with installation/configuration guide
- `LICENSE` (GPL v2)
- `.gitignore` for development workflow

### Note
- **Breaking change**: If upgrading from v1.2.x, deactivate the old "Infobit Sitemap" plugin, delete it, then install this version fresh. Settings will need reconfiguration.

---

## [1.2.1] - 2026-02-04

### Fixed
- **BUG: Wrong timezone on all displayed times** — All admin dashboard times (next queue run, last generated, last Google ping, log entries) used PHP `date()` which outputs UTC. Replaced with `wp_date()` which respects the WordPress timezone setting (e.g., Europe/Rome = UTC+1)
- **BUG: Daily quota counter not resetting at local midnight** — The IndexNow daily quota reset compared against `date('Y-m-d')` (UTC). Changed to `wp_date('Y-m-d')` so the counter resets at local midnight

### Files changed
- `includes/class-indexnow.php` — `check_and_reset_quota()`, `get_stats()`
- `admin/settings-page.php` — All `date()` calls in display

---

## [1.2.0] - 2026-02-04

### Added
- **Sitemap pagination** — Automatic split into multiple files (default 10,000 URLs/file, configurable 1,000–50,000). With 40,000 products: `sitemap-products-1.xml` through `sitemap-products-4.xml`
- **Sitemap index** (`sitemap.xml`) — Dynamically lists all paginated sitemap files with `<lastmod>`
- **Google notification** — `robots.txt` auto-injection + optional Google ping on content change (scheduled 5 minutes after last change to batch updates)
- **"Ping Google" button** in admin dashboard
- **Google info banner** explaining: robots.txt (automatic), Search Console (manual, one-time), Google ping (deprecated since June 2023 but still attempted)
- **IndexNow queued mode** — URLs queued in `wp_options` instead of sent immediately. Processed by WP-Cron at configurable intervals
- **Queue modes**: Immediate, Hourly (recommended), Twice Daily, Daily
- **Queue dashboard**: URL count, next scheduled run, preview of last 5 queued URLs
- **"Process Queue Now" button** — Force immediate batch send
- **"Clear Queue" button** — Discard queued URLs without sending
- **Stock-only update exclusion** — Pre-save snapshot compares title, content, excerpt, price, SKU. If only stock/quantity changed → URL not queued
- **Summary table**: Google (robots.txt + Search Console), Bing (IndexNow), Yandex (IndexNow shared)

### Fixed
- **BUG: Memory crash with 40,000+ products** — `get_posts(-1)` loaded all WP_Post objects into RAM. Replaced with direct `$wpdb->get_results()` using `LIMIT/OFFSET`, loading only ID + post_modified
- **BUG: `generate_sitemap()` did nothing** — Method only updated a timestamp, never generated actual XML. Removed misleading method; sitemap is now served dynamically via rewrite rules
- **BUG: Google never notified** — Only robots.txt was handled, no ping mechanism existed. Added scheduled Google ping
- **BUG: Duplicate URL queueing** — `save_post_product` + `post_updated` hooks could queue the same URL 2-3 times per save. Fixed with static `$queued_this_request` array tracking processed IDs per request
- **BUG: `is_stock_only_update()` unreliable** — Used `did_action('woocommerce_product_set_stock')` which doesn't work in all contexts. Replaced with actual field comparison via pre-save snapshot
- **BUG: Queue stored with autoload=yes** — `update_option()` defaults to `autoload=yes`, causing the queue (potentially thousands of URLs) to load on every WordPress page load. Changed to `add_option(..., 'no')` / `update_option(..., 'no')`
- **BUG: `wp_count_terms()` deprecated** — Called with old single-argument syntax deprecated since WordPress 6.0. Updated to array syntax: `wp_count_terms(array('taxonomy' => ...))`
- **BUG: IndexNow quota inconsistent** — Single URL submit and batch submit counted quota differently. Unified quota tracking

### Changed
- Sitemap content types now include `Cache-Control: public, max-age=3600`
- Backward compatibility: `sitemap-products.xml` redirects to `sitemap-products-1.xml`
- IndexNow batch size configurable (10–10,000, default 100)
- Admin page reorganized with dedicated sections: Sitemap Structure, Google, IndexNow

### Technical notes
- Standard sitemap limit is 50,000 URLs per file, but 10,000 is used by default for better performance and crawler friendliness
- Google deprecated sitemap ping (`/ping?sitemap=`) in June 2023 (returns HTTP 404), but the plugin still attempts it as a fallback
- Google does NOT support IndexNow; the primary method for Google remains `robots.txt` + Search Console
- IndexNow batch POST supports up to 10,000 URLs per request

---

## [1.1.0] - 2026-01-28

### Added
- **IndexNow protocol support** — Instant notification to Bing and Yandex when content changes
- API key auto-generation (32 chars, stored in `wp_options`)
- Key verification file served via rewrite rule (`/{api_key}.txt`)
- Auto-submit on post/page/product publish or update
- Bulk submit: send all published URLs to IndexNow in one click
- Manual URL submission via admin panel
- Daily quota tracking with visual progress bar (default 10,000/day)
- Activity log (last 100 entries)
- Endpoint selection: Bing (default) or Yandex
- Admin section integrated into Sitemap settings page

### Known issues (fixed in v1.2.0)
- ❌ Each product update during stock sync triggers immediate HTTP call → blocks import loop
- ❌ 500 product updates = 500 synchronous HTTP requests
- ❌ Rapidly exhausts daily quota during bulk imports

---

## [1.0.0] - 2026-01-23

### Added
- **Initial release** — Dynamic XML sitemap generator for WordPress/WooCommerce
- Sitemap index + separate sitemaps for pages, posts, products, categories
- Dynamic generation via WordPress rewrite rules (no static files)
- Auto-update timestamp on content change
- Configurable content types (pages, posts, products, categories, tags)
- URL exclusion by pattern (one per line)
- ID exclusion (comma-separated)
- Priority and changefreq configuration per content type
- WooCommerce functional pages auto-excluded (cart, checkout, my-account)
- `robots.txt` automatic sitemap declaration
- Admin settings page with statistics
- Plugin settings link in plugins list

### Context
- Created to replace static XML sitemap files for infobitcomputer.it
- Original static sitemaps contained only 27 of 235 products (manually scraped)
- Plugin generates complete sitemap from database automatically

---

## Development Roadmap

### Planned features
- [x] Google Rich Results / structured data for WooCommerce products (v1.4.0)
- [ ] Image sitemap support (`<image:image>` tag)
- [ ] Video sitemap support
- [ ] News sitemap support
- [ ] Custom post type support (configurable)
- [ ] Exclude by category/taxonomy
- [ ] Priority auto-calculation based on page depth/traffic
- [ ] Sitemap caching with transient API
- [ ] WP-CLI commands for generation/ping
- [ ] Multisite support
- [ ] Export/Import settings
- [ ] REST API endpoint for sitemap stats
- [ ] Integration with popular SEO plugins (Yoast, Rank Math) for priority data

### Known limitations
- Google Search Console must be configured manually (one-time)
- WP-Cron depends on site traffic; for guaranteed timing, configure system cron
- IndexNow only supports Bing/Yandex (Google does not participate)
- No built-in sitemap caching (served dynamically on each request)
