# SRKPICS Yoast Meta Bulk Importer

Bulk import Yoast SEO Meta Titles and Meta Descriptions for WordPress posts, pages, products, categories, brands, vendors, and custom taxonomies using a CSV file.

## Description

SRKPICS Yoast Meta Bulk Importer helps you update Yoast SEO metadata in bulk without manually editing each page. Simply upload a CSV file containing URLs and SEO data, and the plugin will automatically locate the matching content and update the associated Yoast SEO fields.

The plugin is designed for stores and websites with large numbers of products, categories, pages, and blog posts, making SEO updates fast and efficient.

## Features

* Bulk import Yoast SEO Meta Titles
* Bulk import Yoast SEO Meta Descriptions
* URL-based content matching
* Supports:

  * Products
  * Product Categories
  * Posts
  * Pages
  * Brands
  * Vendors
  * Custom Taxonomies
* AJAX batch processing
* Live import progress log
* Success, Failed, and Skipped counters
* Duplicate URL detection
* UTF-8 CSV support
* Automatic handling of missing title or description fields
* Safe update process with validation

## CSV Format

### Import Title and Description

```csv
URL,Meta Title,Meta Description
https://example.com/product/sample-product/,Sample Meta Title,Sample Meta Description
```

### Import Description Only

```csv
URL,Meta Description
https://example.com/product/sample-product/,Sample Meta Description
```

### Import Rules

| Meta Title | Meta Description | Result                   |
| ---------- | ---------------- | ------------------------ |
| Filled     | Filled           | Imports both             |
| Filled     | Empty            | Imports title only       |
| Empty      | Filled           | Imports description only |
| Empty      | Empty            | Skips row                |

## Installation

1. Download the plugin ZIP file.
2. Log in to your WordPress Admin Dashboard.
3. Go to Plugins → Add New → Upload Plugin.
4. Upload the plugin ZIP file.
5. Click Install Now.
6. Activate the plugin.
7. Navigate to the plugin import page from the WordPress admin menu.

## Usage

1. Prepare a CSV file using the required format.
2. Save the file as **CSV UTF-8 (Comma Delimited)**.
3. Upload the CSV file through the plugin interface.
4. Start the import process.
5. Monitor the live import log and progress.
6. Review the success, failed, and skipped results when complete.

## Requirements

* WordPress 6.0 or higher
* PHP 7.4 or higher
* Yoast SEO Plugin

## Tested With

* WordPress 6.x
* WooCommerce
* Yoast SEO

## Changelog

### 1.0.0

* Initial release
* Bulk Yoast Meta Title import
* Bulk Yoast Meta Description import
* URL-based matching
* Taxonomy and custom content support
* AJAX batch processing
* Live import log

## Author

SRKPICS

## License

GPL v2 or later
