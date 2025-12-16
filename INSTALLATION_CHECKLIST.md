# Installation Checklist

Use this checklist to track your progress. Check off each item as you complete it.

## Pre-Installation

- [ ] I know where my WordPress installation is located
- [ ] I can access my WordPress files (FTP, SSH, or file manager)
- [ ] Composer is installed on my computer/server (run `composer --version` to check)
- [ ] WooCommerce plugin is installed and activated on my WordPress site

## Installation Steps

### Step 1: Get Plugin Files
- [ ] I have chosen my method:
  - [ ] Method A: Clone from GitHub (recommended)
  - [ ] Method B: Download ZIP and upload manually

### Step 2: Place Plugin in WordPress
- [ ] I have located my WordPress `wp-content/plugins/` folder
- [ ] Plugin folder is now at: `wp-content/plugins/woo-artkey-suite-plugin/`
- [ ] I can see `woo-artkey-suite.php` file inside the plugin folder

### Step 3: Install QR Code Library
- [ ] I have navigated to the plugin folder in terminal/command line
- [ ] I have run: `composer install`
- [ ] I can see a `vendor/` folder was created
- [ ] I can see `vendor/endroid/qr-code/` folder exists

### Step 4: Activate Plugin
- [ ] I have logged into WordPress Admin
- [ ] I went to: Plugins → Installed Plugins
- [ ] I found "Woo Art Key Suite" in the list
- [ ] I clicked "Activate"
- [ ] I see "Plugin activated successfully" message

## Verification

- [ ] Plugin shows as "Active" in Plugins list
- [ ] I can go to WooCommerce → Products → Edit a product
- [ ] I can see "Enable Art Key" checkbox in the product editor
- [ ] (Optional) I tested the REST API: `wp-json/woo-artkey-suite/v1/templates` returns JSON

## Troubleshooting (If Needed)

- [ ] I checked PHP version (needs 7.4 or higher)
- [ ] I checked that WooCommerce is installed
- [ ] I checked WordPress error logs if plugin won't activate
- [ ] I verified `vendor/endroid/qr-code/` folder exists if QR code fails

---

## Current Status

**Where am I?** ___________________________________________

**What step am I on?** ___________________________________

**Any errors?** ___________________________________________

---

## Need Help?

Refer to:
- `QUICK_START.md` - Quick 5-step guide
- `COMPLETE_INSTALLATION_GUIDE.md` - Detailed step-by-step instructions

