# Complete Installation Guide - Woo Art Key Suite Plugin

## 📋 Table of Contents
1. [Understanding What We Have](#understanding-what-we-have)
2. [Understanding What We Need to Do](#understanding-what-we-need-to-do)
3. [Step-by-Step Installation](#step-by-step-installation)
4. [Verification Steps](#verification-steps)
5. [Troubleshooting](#troubleshooting)

---

## Understanding What We Have

### Current File Locations

#### On Your Computer (Local):
```
c:\Users\email\Downloads\plugin-repo\
├── woo-artkey-suite.php          ← Main plugin file
├── composer.json                  ← Dependencies file
├── README.md                      ← Documentation
├── .gitignore                     ← Git ignore rules
└── SETUP_GITHUB_REPO.md          ← Setup instructions
```

#### On GitHub:
- Repository: `https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin`
- Contains all the same files

---

## Understanding What We Need to Do

### The Big Picture:

1. **Get the plugin files to your WordPress server**
   - Either clone from GitHub OR copy files manually

2. **Install the QR code library**
   - Run `composer install` to download `endroid/qr-code` library

3. **Activate the plugin in WordPress**
   - WordPress will find the plugin and you can activate it

4. **Verify it works**
   - Check that plugin activates
   - Check that QR code library is loaded

---

## Step-by-Step Installation

### Prerequisites Checklist

Before starting, you need:
- [ ] WordPress installation location (where is it?)
  - Local development? (XAMPP, MAMP, Local by Flywheel, etc.)
  - Remote server? (shared hosting, VPS, etc.)
- [ ] Access to WordPress files (via FTP, SSH, or file manager)
- [ ] Composer installed (PHP dependency manager)
  - Check: Open terminal and type `composer --version`
  - If not installed: https://getcomposer.org/download/

---

### Option A: Installation on Local WordPress (Development)

**If your WordPress is on your local computer:**

#### Step 1: Find Your WordPress Plugins Directory

Typical locations:
- **XAMPP**: `C:\xampp\htdocs\your-site\wp-content\plugins\`
- **MAMP**: `C:\MAMP\htdocs\your-site\wp-content\plugins\`
- **Local by Flywheel**: `C:\Users\email\Local Sites\your-site\app\public\wp-content\plugins\`
- **Custom**: `C:\path\to\your\wordpress\wp-content\plugins\`

**Your WordPress plugins folder should look like:**
```
wp-content\
  └── plugins\
      ├── akismet\
      ├── hello.php
      └── (other plugins)
```

#### Step 2: Copy Plugin Files

**Method 1: Clone from GitHub (Recommended)**

1. Open PowerShell or Command Prompt
2. Navigate to your WordPress plugins directory:
   ```powershell
   cd "C:\path\to\your\wordpress\wp-content\plugins"
   ```
   (Replace with your actual path)

3. Clone the repository:
   ```powershell
   git clone https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin.git
   ```

4. This creates a folder: `wp-content\plugins\woo-artkey-suite-plugin\`

**Method 2: Manual Copy**

1. Go to: `c:\Users\email\Downloads\plugin-repo\`
2. Copy the entire folder
3. Paste it into your WordPress plugins directory
4. Rename it to: `woo-artkey-suite` (optional, but cleaner)

#### Step 3: Install QR Code Library

1. Navigate to the plugin folder:
   ```powershell
   cd "C:\path\to\your\wordpress\wp-content\plugins\woo-artkey-suite-plugin"
   ```
   (Or wherever you copied/cloned it)

2. Run Composer to install dependencies:
   ```powershell
   composer install
   ```

3. This creates a `vendor\` folder with the QR code library inside

4. **Verify it worked:**
   ```powershell
   Test-Path "vendor\endroid\qr-code"
   ```
   Should return: `True`

#### Step 4: Activate Plugin in WordPress

1. Open WordPress Admin in your browser:
   - Usually: `http://localhost/your-site/wp-admin`
   - Or: `http://your-site.local/wp-admin`

2. Go to: **Plugins** → **Installed Plugins**

3. Find: **"Woo Art Key Suite (Divi-ready, Moderated, Noindex, reCAPTCHA, Google Fonts)"**

4. Click: **Activate**

5. You should see: "Plugin activated successfully"

---

### Option B: Installation on Remote Server (Production)

**If your WordPress is on a remote server (shared hosting, VPS, etc.):**

#### Step 1: Access Your Server

You need one of these:
- **FTP/SFTP** (FileZilla, WinSCP, etc.)
- **SSH** (PuTTY, terminal)
- **cPanel File Manager** (if your host provides it)

#### Step 2: Locate WordPress Plugins Directory

On your server, the path is usually:
```
/home/username/public_html/wp-content/plugins/
```
Or:
```
/var/www/html/wp-content/plugins/
```
(Your host documentation will tell you the exact path)

#### Step 3: Upload Plugin Files

**Method 1: Clone via SSH (If you have SSH access)**

1. Connect via SSH:
   ```bash
   ssh username@your-server.com
   ```

2. Navigate to plugins directory:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   ```

3. Clone repository:
   ```bash
   git clone https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin.git
   ```

4. Install dependencies:
   ```bash
   cd woo-artkey-suite-plugin
   composer install
   ```

**Method 2: Upload via FTP**

1. Download plugin files from GitHub:
   - Go to: https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin
   - Click: **Code** → **Download ZIP**
   - Extract the ZIP file

2. Connect via FTP to your server

3. Navigate to: `wp-content/plugins/`

4. Upload the entire `woo-artkey-suite-plugin` folder

5. **Important:** You still need to install Composer dependencies:
   - Either via SSH (run `composer install`)
   - Or upload the `vendor` folder (see alternative method below)

**Method 3: Alternative - Pre-build with vendor folder**

If you can't run `composer` on your server:

1. On your local computer, go to: `c:\Users\email\Downloads\plugin-repo\`
2. Run: `composer install`
3. This creates a `vendor\` folder
4. Zip the entire folder (including `vendor\`)
5. Upload to server via FTP
6. Extract in `wp-content/plugins/`

#### Step 4: Activate Plugin in WordPress

Same as Option A, Step 4:
1. Go to WordPress Admin → Plugins
2. Find "Woo Art Key Suite"
3. Click Activate

---

## Verification Steps

### Step 1: Check Plugin is Active

1. WordPress Admin → **Plugins** → **Installed Plugins**
2. Look for "Woo Art Key Suite" - should show "Active"

### Step 2: Check QR Code Library Status

1. WordPress Admin → Look for **"Woo Art Key Suite"** in the menu (if you added a settings page)
2. Or check error logs:
   - WordPress Admin → **Tools** → **Site Health** → **Info** → **Server**
   - Check PHP error logs

### Step 3: Test Plugin Functionality

1. Go to: **WooCommerce** → **Products** → **Add New** (or edit existing)
2. Scroll down to **Product Data**
3. Look for: **"Enable Art Key"** checkbox
4. If you see it, the plugin is working! ✅

### Step 4: Check REST API Endpoints

1. Visit in browser:
   ```
   http://your-site.com/wp-json/woo-artkey-suite/v1/templates
   ```
2. Should return JSON data with templates
3. If you see JSON, the REST API is working! ✅

---

## Troubleshooting

### Problem: "Composer command not found"

**Solution:**
- Install Composer: https://getcomposer.org/download/
- Or use the alternative method (pre-build vendor folder locally)

### Problem: "Plugin could not be activated because it triggered a fatal error"

**Solution:**
1. Check WordPress error logs
2. Common causes:
   - Missing PHP extensions (GD Library for images)
   - PHP version too old (needs 7.4+)
   - Missing WooCommerce plugin

### Problem: "QR code library not found"

**Solution:**
1. Verify `vendor\endroid\qr-code` folder exists
2. If not, run `composer install` again
3. Check file permissions on server (folders need execute permissions)

### Problem: "Cannot find WordPress plugins directory"

**Solution:**
1. Look for `wp-config.php` file
2. Plugins folder is always: `wp-content/plugins/` (relative to wp-config.php)

---

## Quick Reference: File Locations

### After Installation, Your Structure Should Be:

```
your-wordpress-site/
└── wp-content/
    └── plugins/
        └── woo-artkey-suite-plugin/    ← Plugin folder
            ├── woo-artkey-suite.php     ← Main plugin file
            ├── composer.json
            ├── vendor/                  ← Created by composer install
            │   └── endroid/
            │       └── qr-code/        ← QR code library
            ├── README.md
            └── .gitignore
```

---

## Need More Help?

If you get stuck at any step, provide:
1. Which step you're on
2. What error message you see (if any)
3. Your WordPress setup (local or remote?)
4. Your file paths

And I'll help you troubleshoot!

