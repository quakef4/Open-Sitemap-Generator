# Open Sitemap Generator

[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-blue.svg)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-Compatible-96588a.svg)](https://woocommerce.com/)
[![License](https://img.shields.io/badge/License-GPL%20v2-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-1.4.0-orange.svg)](CHANGELOG.md)

**Dynamic XML sitemap generator for WordPress** with automatic pagination, IndexNow support (Bing/Yandex), Google Rich Results for WooCommerce, and Google notification. Optimized for WooCommerce stores with tens of thousands of products.

## Features

- **Automatic pagination** — Splits sitemaps into files of 10,000 URLs (configurable). 40,000 products = 4 sitemap files, automatically
- **Optimized database queries** — Direct SQL with `LIMIT/OFFSET`, never loads 40,000+ WP_Post objects into memory
- **Google Rich Results** — Enhances WooCommerce product schema with shipping, return policy, reviews, and seller data for better search visibility
- **IndexNow queued mode** — URLs queued and sent in batches via WP-Cron, never blocks WooCommerce stock sync loops
- **Stock update filtering** — Ignores changes that only affect stock quantity/status (compares title, content, price, SKU)
- **Google notification** — Automatic `robots.txt` sitemap declaration + optional ping
- **Bing/Yandex notification** — Instant via IndexNow protocol with configurable batch scheduling
- **Full admin dashboard** — Statistics, queue management, Rich Results self-test, activity log, one-click actions

## Google Rich Results

The Rich Results module adds structured data (JSON-LD) to WooCommerce products for improved Google search appearance:

| Schema Field | Type | Description |
|--------------|------|-------------|
| `shippingDetails` | `OfferShippingDetails` | Shipping cost (read from WooCommerce zones), destination country, handling + transit times |
| `hasMerchantReturnPolicy` | `MerchantReturnPolicy` | Return days, return fees, return shipping cost, policy URL |
| `aggregateRating` | `AggregateRating` | Average rating and review count |
| `review` | `Review[]` | Individual reviews with author, rating, date, body |
| `seller` | `Organization` | Merchant name and URL inside offers |
| Product name | Truncation | Truncates names exceeding max length (default 150 chars) |

### Configuration

Go to **Settings > Open Sitemap > Opzioni Rich Results** and configure:

- Merchant name and URL
- Shipping country (ISO 3166-1 alpha-2)
- Handling and transit times
- Return policy (days, URL, fees type, shipping fees amount)
- Reviews (enable/disable, max per product)
- Product name max length

### Self-Test

Click **"Esegui Test Rich Results"** in the admin dashboard to validate schema generation on a real product. The test checks:

- WooCommerce active
- Merchant configured
- Return policy URL
- Schema generation on a real product
- All required fields: `shippingRate`, `shippingDestination`, `deliveryTime`, `returnPolicy`, `returnShippingFeesAmount`, `aggregateRating`/`review`, `seller` in offers

## Sitemap Structure

With 40,000 products and 10,000 URLs/file limit:

```
sitemap.xml (index)
├── sitemap-pages-1.xml        (pages)
├── sitemap-products-1.xml     (products 1-10,000)
├── sitemap-products-2.xml     (products 10,001-20,000)
├── sitemap-products-3.xml     (products 20,001-30,000)
├── sitemap-products-4.xml     (products 30,001-40,000)
└── sitemap-categories-1.xml   (categories)
```

## Search Engine Notifications

| Engine | Method | Status |
|--------|--------|--------|
| **Google** | `robots.txt` + Search Console + Rich Results | Automatic |
| **Bing** | IndexNow (queued batch) | Instant |
| **Yandex** | IndexNow (shared from Bing) | Instant |

> **Note:** Google does NOT support IndexNow. The primary method for Google is `robots.txt` + manual Search Console registration. Rich Results improve product visibility in search.

## Requirements

- WordPress 5.0+
- PHP 7.4+
- WooCommerce (optional, required for product support and Rich Results)

## Installation

### From GitHub

1. Download the latest release ZIP
2. In WordPress admin: **Plugins > Add New > Upload Plugin**
3. Upload the ZIP file and activate
4. Go to **Settings > Permalinks** and click **Save** (activates rewrite rules)
5. Go to **Settings > Open Sitemap** and configure

### Manual

1. Clone or download this repository
2. Copy the `open-sitemap-generator` folder to `/wp-content/plugins/`
3. Activate in WordPress admin
4. Save Permalinks and configure

## Configuration

### Recommended settings for e-commerce

| Setting | Value | Why |
|---------|-------|-----|
| Queue mode | **Hourly** | Batches stock sync updates |
| Exclude stock-only updates | **Enabled** | Prevents quota waste during bulk imports |
| Max URLs per sitemap | **10,000** | Good balance of performance and size |
| Batch size | **100** | Safe for most hosting |
| Ping Google on change | **Enabled** | Deprecated but harmless |
| Rich Results | **Enabled** | Better product visibility in Google |
| Merchant name | **Your store name** | Required for seller schema |
| Return days | **14** | EU standard |

### WP-Cron accuracy

WordPress uses a pseudo-cron triggered by site visits. For guaranteed timing:

1. Add to `wp-config.php`:
   ```php
   define('DISABLE_WP_CRON', true);
   ```

2. Add system cron (cPanel/Plesk):
   ```bash
   */15 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
   ```

For sites with regular traffic, the default WP-Cron is sufficient.

## File Structure

```
open-sitemap-generator/
├── open-sitemap-generator.php    # Main plugin file (v1.4.0)
├── includes/
│   ├── class-indexnow.php        # IndexNow queue system
│   └── class-rich-results.php    # Google Rich Results schema enhancement
├── admin/
│   ├── settings-page.php         # Admin dashboard
│   ├── style.css                 # Admin styles
│   └── script.js                 # Admin AJAX handlers
├── CHANGELOG.md                  # Full version history
├── README.md                     # This file
├── LICENSE                       # GPL v2
└── readme.txt                    # WordPress.org format
```

## Hooks & Filters

### Actions
- `osg_notify_google` — Fires when Google ping is scheduled
- `osg_indexnow_process_queue` — Fires when IndexNow queue is processed

### Filters
- `robots_txt` — Adds sitemap URL to robots.txt
- `woocommerce_structured_data_product` — Rich Results module enhances product JSON-LD (priority 99)

## Contributing

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/my-feature`
3. Commit changes: `git commit -m 'Add my feature'`
4. Push: `git push origin feature/my-feature`
5. Open a Pull Request

Please see [CHANGELOG.md](CHANGELOG.md) for the development roadmap and known limitations.

## License

This project is licensed under the GPL v2 or later — see [LICENSE](LICENSE) for details.

## Credits

Developed by [quakef4](https://github.com/quakef4).
