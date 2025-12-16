# Woo Art Key Suite

WordPress plugin for WooCommerce that enables custom "Art Key" products with QR code generation and image composition.

## Features

- Custom "Art Key" post type for mobile landing pages
- QR code generation using `endroid/qr-code`
- Server-side image composition (overlaying QR codes onto user designs)
- WooCommerce product integration
- REST API endpoints for headless frontend integration
- Cross-platform, cross-browser compatible frontend UI

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- GD Library (for image manipulation)
- Composer (for dependency management)

## Installation

### Step 1: Install via Composer

```bash
composer install
```

This will install the `endroid/qr-code` library and its dependencies into the `vendor/` directory.

### Step 2: Install the Plugin

1. Copy this entire directory to your WordPress installation:
   ```
   wp-content/plugins/woo-artkey-suite/
   ```

2. Activate the plugin in WordPress Admin:
   - Go to **Plugins** → **Installed Plugins**
   - Find "Woo Art Key Suite"
   - Click **Activate**

### Step 3: Verify Installation

1. Go to **WooCommerce** → **Settings** → **Products**
2. Edit a product and look for the "Art Key" section
3. Check that the QR code library is loaded (Settings page should show status)

## Usage

### Enabling Art Key on a Product

1. Edit a WooCommerce product
2. In the Product Data section, check "Enable Art Key"
3. Optionally check "Requires QR Code" if the product needs QR code generation
4. Save the product

### REST API Endpoints

The plugin provides the following REST API endpoints for headless frontend integration:

- `GET /wp-json/woo-artkey-suite/v1/templates` - Get available Art Key templates
- `POST /wp-json/woo-artkey-suite/v1/artkey/(?P<id>\d+)/design` - Save user design and template selection
- `GET /wp-json/woo-artkey-suite/v1/print-image/(?P<id>\d+)` - Get composited print image URL

## QR Code Library Setup

The plugin requires the `endroid/qr-code` PHP library. This is automatically installed via Composer.

If you encounter issues:

1. Ensure Composer is installed: `composer --version`
2. Run `composer install` from the plugin directory
3. Check that `vendor/endroid/qr-code/` exists

## Development

### Project Structure

```
woo-artkey-suite/
├── woo-artkey-suite.php    # Main plugin file
├── composer.json            # PHP dependencies
├── vendor/                  # Composer dependencies (generated)
└── README.md               # This file
```

## Support

For issues or questions, please refer to the plugin settings page in WordPress admin or check the error logs.

## License

[Your License Here]

