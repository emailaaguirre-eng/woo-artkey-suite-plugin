# Do I Need Shortcodes? - Explanation Guide

## Short Answer

**For your React frontend:** ❌ **NO** - Use REST API endpoints instead

**For Art Key landing pages (QR code destinations):** ✅ **YES** - Shortcodes are needed to display the pages

---

## Detailed Explanation

### Two Different Parts of Your System

Your plugin serves **two different purposes**:

#### 1. **React Frontend** (Your Main Website)
- This is your headless e-commerce site
- Users browse products, customize designs, select templates
- **Uses REST API endpoints** - No shortcodes needed!

#### 2. **Art Key Landing Pages** (QR Code Destinations)
- These are the mobile pages users visit when they scan the QR code
- Stored as WordPress posts/pages
- **Uses shortcodes** to display the content

---

## How It Works

### For Your React Frontend:

You'll make API calls to these REST endpoints:

```
GET  /wp-json/woo-artkey-suite/v1/templates
     → Get available Art Key templates

POST /wp-json/woo-artkey-suite/v1/artkey/{id}/design
     → Save user design and template selection

GET  /wp-json/woo-artkey-suite/v1/print-image/{id}
     → Get the final composited image (with QR code)
```

**No shortcodes needed here!** ✅

---

### For Art Key Landing Pages:

When a user scans a QR code, they visit a WordPress page that looks like:

```php
[artkey_block]  ← This shortcode displays the entire Art Key page
```

The shortcode renders:
- User's customized content
- Links
- Images/videos
- Guestbook
- Upload sections

**Shortcodes ARE needed here** ✅

---

## Available Shortcodes

The plugin provides these shortcodes:

| Shortcode | Purpose | Where Used |
|-----------|---------|------------|
| `[artkey_block]` | Displays the Art Key landing page content | On the Art Key page itself |
| `[artkey_editor]` | Displays the editor interface | On editor page (if using WordPress frontend) |
| `[artkey_guestbook]` | Displays guestbook section | Can be added to Art Key page |
| `[artkey_upload]` | Displays upload section | Can be added to Art Key page |

---

## Typical Workflow

### 1. User Customizes Product (React Frontend)

```
React App
  ↓ API Call
POST /wp-json/woo-artkey-suite/v1/artkey/{id}/design
  ↓ Saves
WordPress creates/updates Art Key post
```

**No shortcodes used here!**

---

### 2. QR Code Generated

```
WordPress generates QR code
  ↓ Points to
WordPress page with [artkey_block] shortcode
```

**Shortcode is on the WordPress page!**

---

### 3. User Scans QR Code

```
QR Code
  ↓ Opens
WordPress page URL
  ↓ Displays
[artkey_block] shortcode renders the content
```

**Shortcode displays the page!**

---

## For Your Headless Setup

Since you're building a headless site with React:

### ✅ What You NEED:

1. **REST API endpoints** - Already provided by the plugin
2. **QR code generation** - Handled by the plugin backend
3. **Image composition** - Handled by the plugin backend

### ❌ What You DON'T Need (for React frontend):

1. **Shortcodes in your React app** - Use API calls instead
2. **WordPress frontend UI** - You're building that in React

### ⚠️ What You DO Need (for Art Key pages):

1. **WordPress pages** - To host the Art Key landing pages
2. **Shortcodes on those pages** - To display the Art Key content
   - These pages are what the QR codes point to
   - They're separate from your main React site

---

## Setup Options

### Option 1: Keep Shortcodes (Recommended)

**Why:** Art Key landing pages need to display content

**What to do:**
- When an Art Key is created, also create a WordPress page
- Add `[artkey_block]` shortcode to that page
- The QR code points to this page
- Your React frontend uses REST API

**Pros:**
- Works out of the box
- Art Key pages can be viewed on mobile
- No additional setup needed

---

### Option 2: Headless Art Key Pages (Advanced)

**Why:** You want everything in React

**What to do:**
- Build React components that replicate shortcode functionality
- Have QR codes point to your React app routes
- Use REST API to fetch Art Key data
- Render the page in React

**Pros:**
- Everything in one codebase
- Full control over styling/UX

**Cons:**
- More development work
- Need to replicate shortcode functionality in React
- QR codes point to React app (not WordPress)

---

## Recommendation

**For most headless setups, keep the shortcodes for Art Key pages:**

- Your React frontend: Use REST API ✅
- Art Key landing pages: Use WordPress + shortcodes ✅
- QR codes: Point to WordPress pages with shortcodes ✅

This gives you:
- Clean separation of concerns
- Art Key pages work independently
- No need to rebuild shortcode functionality in React
- QR codes work reliably (point to stable WordPress URLs)

---

## Summary

**Question: Do I need shortcodes?**

**Answer:**
- **React frontend:** No, use REST API
- **Art Key landing pages:** Yes, use `[artkey_block]` shortcode
- **QR code destinations:** Yes, WordPress pages with shortcodes

The plugin includes both:
- ✅ REST API endpoints (for your React frontend)
- ✅ Shortcodes (for Art Key landing pages)

You can use both! They serve different purposes. 🎯

