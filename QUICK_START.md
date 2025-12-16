# Quick Start Guide - Installation in 5 Steps

## 🚀 Fast Installation (Choose Your Method)

---

## Method 1: I Have WordPress on My Computer (Local)

### Step 1: Find Your WordPress Plugins Folder
Common locations:
- XAMPP: `C:\xampp\htdocs\your-site-name\wp-content\plugins\`
- Local by Flywheel: `C:\Users\email\Local Sites\site-name\app\public\wp-content\plugins\`

### Step 2: Clone the Plugin
Open PowerShell in that folder and run:
```powershell
git clone https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin.git
```

### Step 3: Install QR Library
```powershell
cd woo-artkey-suite-plugin
composer install
```

### Step 4: Activate in WordPress
1. Go to: `http://localhost/your-site/wp-admin`
2. Plugins → Installed Plugins
3. Find "Woo Art Key Suite"
4. Click **Activate**

✅ **Done!**

---

## Method 2: I Have WordPress on a Server (Remote)

### Step 1: Access Your Server
- Use FTP (FileZilla) or cPanel File Manager
- Navigate to: `wp-content/plugins/`

### Step 2: Upload Plugin
**Option A - Via SSH (if available):**
```bash
cd /path/to/wordpress/wp-content/plugins/
git clone https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin.git
cd woo-artkey-suite-plugin
composer install
```

**Option B - Via FTP:**
1. Download ZIP from GitHub: https://github.com/emailaaguirre-eng/woo-artkey-suite-plugin
2. Extract locally
3. Run `composer install` locally (creates vendor folder)
4. Upload entire folder via FTP to `wp-content/plugins/`

### Step 3: Activate in WordPress
1. Go to your WordPress admin
2. Plugins → Installed Plugins
3. Find "Woo Art Key Suite"
4. Click **Activate**

✅ **Done!**

---

## Need Detailed Instructions?

See `COMPLETE_INSTALLATION_GUIDE.md` for step-by-step instructions with explanations.

---

## Verify It Works

1. **Check Plugin is Active:**
   - WordPress Admin → Plugins → Should show "Active"

2. **Test Product Integration:**
   - WooCommerce → Products → Edit a product
   - Look for "Enable Art Key" checkbox
   - If you see it, it's working! ✅

3. **Test REST API:**
   - Visit: `http://your-site.com/wp-json/woo-artkey-suite/v1/templates`
   - Should show JSON data

---

## Common Issues

**"Composer not found"**
→ Install from: https://getcomposer.org/download/

**"Plugin won't activate"**
→ Check PHP version (needs 7.4+)
→ Check WooCommerce is installed
→ Check error logs

**"QR code library missing"**
→ Run `composer install` in the plugin folder

