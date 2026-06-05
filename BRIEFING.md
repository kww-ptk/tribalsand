# TRIBAL SAND — Claude Code Project Briefing
## Complete PHP Website Rebuild · SEO-First · April 2026

---

## 1. WHO YOU ARE WORKING WITH

**Brand:** TribalSand (tribalsand.com)
**Category:** Luxury sustainable beachfront hospitality ecosystem · Kenya's North Coast
**Locations:** Watamu, Kilifi, Vipingo, Kenya
**Core positioning:** "Kenya as it was meant to be experienced."
**Company:** Tribalsand LLC · U.S.-based company

**Contact:**
- Phone: +254 115 115 247
- Email: reservations@tribalsand.com
- WhatsApp: https://wa.me/254115115247
- Booking engine: https://book.tribalsand.com/booking/chain-tribalsand-en

---

## 2. THE BRIEF

Rebuild the entire tribalsand.com website in PHP with:
1. **Best-in-class technical SEO** throughout every page
2. **Full image optimisation** (WebP, srcset, lazy loading, aspect ratios)
3. **Preserve existing URL structure exactly** — many pages have Google rankings we cannot lose
4. **Modern, elegant design** using the brand system already built (see Section 4)
5. **Fast, clean PHP** — no unnecessary frameworks, Bootstrap removed, custom CSS only
6. **Shared includes system** — header.php, footer.php, head.php, schema.php

---

## 3. CRITICAL: URL STRUCTURE — DO NOT CHANGE

These are the exact file names that must be preserved to protect existing rankings:

### Core Pages (keep exactly)
- `index.php` — Homepage
- `zuri.php` — Zuri boutique hotel
- `maya-kobe.php` — Maya Kobe boutique hotel
- `my-amani.php` — My Amani private villa
- `enkare-bofa.php` — Enkare Bofa villa
- `sandbox.php` — Sandbox villa
- `maya_ilai.php` — Maya Ilai eco compound (note underscore — keep it)
- `activities.php` — Activities page
- `events.php` — Events page
- `contact.php` — Contact page
- `blog.php` — Blog listing
- `sustainability.php` — Sustainability page
- `tribalsandstory.php` — Our Story
- `for-agents.php` — For Agents

### Gallery Pages (keep exactly)
- `my-amani-gallery.php`
- `maya-kobe-gallery.php`
- `maya-ilai-gallery.php`
- `zuri-gallery.php`
- `enkarebofa-gallery.php`
- `sandbox-gallery.php`
- `events-gallery.php`

### Legal Pages (keep exactly)
- `privacy_policy.php`
- `sffp.php` — Smoke-Free Facility Policy
- `tc.php` — Terms & Conditions
- `licences.php`

### New Pages to Create
- `trip-builder.php` — Trip builder wizard (replace old booking form)
- `tribalsand-blog-tribal-dunes.html` → migrate to `tribal-dunes.php`
- `off-duty.php` — Coming soon page for Off Duty coworking hotel
- `tribal-table.php` — Coming soon page
- `somewhere-cafe.php` — Coming soon page

### SEO Redirects Required (.htaccess)
```apache
# Add redirects for any old URLs that may have changed
Redirect 301 /tribalsand-blog-tribal-dunes.html /tribal-dunes.php
```

---

## 4. BRAND DESIGN SYSTEM

### Fonts
- **Display:** Cormorant Garamond (Google Fonts) — serif, weights 300/400/500/italic
- **Body:** Jost (Google Fonts) — sans-serif, weights 300/400/500

### Colour Tokens
```css
:root {
  --sand: #B8965A;
  --sand-lt: #D4B07A;
  --sand-pale: #F2E8D6;
  --sand-faint: #FAF6EE;
  --teal: #1E5C6B;
  --teal-d: #102F3A;
  --teal-m: #2D7A8C;
  --dark: #141412;
  --off: #FAF8F4;
  --white: #fff;
  --mid: #6B6050;
  --light: #A89880;
  --border: rgba(184, 150, 90, .14);
}
```

### Logo Files
- `images/whitelogo11.png` — white logo for dark backgrounds (nav, footer)
- `images/footerlogo.png` — footer version

### Design Principles
- Transparent nav on hero pages → dark teal on scroll (`scrolled60` class)
- Cormorant Garamond for all headings with `em` in italic teal
- Jost for all body, labels, buttons
- Sand gold (#B8965A) for accents, dividers, eyebrow labels
- Off-white (#FAF8F4) background, never pure white pages
- Minimal borders: `rgba(184,150,90,.14)`

---

## 5. SHARED INCLUDES SYSTEM

### includes/head.php
Every page should include this in `<head>`. It handles:
- Google Fonts (Cormorant Garamond + Jost)
- Font Awesome 6.4.0
- Canonical URL (dynamic per page)
- Open Graph tags (dynamic per page)
- Twitter Card tags
- JSON-LD structured data (dynamic per page)
- Preconnect hints
- Global CSS

Usage pattern:
```php
<?php
$page_title = "Zuri Boutique Hotel · Watamu · Tribal Sand Kenya";
$page_desc = "Zuri is a luxury beachfront boutique hotel in Watamu...";
$page_url = "https://tribalsand.com/zuri.php";
$page_image = "https://tribalsand.com/images/zuri/Aerial/zuri-3.webp";
$page_schema = 'LodgingBusiness'; // triggers correct JSON-LD
include 'includes/head.php';
?>
```

### includes/header.php
Already built. Contains:
- Full navigation with dropdowns (Accommodations, Experiences, Tribal Dunes, Events, Gallery, About)
- Accommodations: 3-column dropdown with property thumbnails (Beachfront Boutique Hotels / Beachfront Private Villas / Tribal Dunes)
- Tribal Dunes: 2-column dropdown (Maya Kobe, Maya Ilai, Off Duty / Tribal Table-Soon, Somewhere Café-Soon, Kite School-Soon)
- gtranslate widget (en/fr/sw/de/hi/it/zh-TW, flag_size 14)
- Transparent → dark teal scroll behaviour
- Mobile full-screen drawer with all properties and links
- Right side: phone number, Plan Your Trip button, Book Now button
- Body padding-top: 68px applied via JS

### includes/footer.php
Already built. Contains:
- 5-column layout: Brand | Boutique Hotels | Private Villas | Tribal Dunes | Discover | Company
- Brand column: logo (48px), description, contact links, social icons
- Trust signal badge (pulsing green dot, rotating messages, appears under social icons)
- Bottom bar: copyright + legal links
- LeadConnector chat widget script

### includes/schema.php
New file to create. Handles all JSON-LD structured data:
- `Organization` schema for all pages
- `LodgingBusiness` for property pages
- `BreadcrumbList` for all pages
- `FAQPage` for pages with FAQs
- `LocalBusiness` for contact page

---

## 6. SEO REQUIREMENTS — EVERY PAGE

### Technical SEO Checklist (every .php file)
```html
<!-- In <head> -->
<title>{Unique Page Title} · Tribal Sand Kenya</title>
<meta name="description" content="{150-160 char unique description}">
<link rel="canonical" href="https://tribalsand.com/{page}.php">

<!-- Open Graph -->
<meta property="og:title" content="{title}">
<meta property="og:description" content="{description}">
<meta property="og:image" content="{full URL to 1200x630 image}">
<meta property="og:url" content="https://tribalsand.com/{page}.php">
<meta property="og:type" content="website">
<meta property="og:site_name" content="Tribal Sand">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{title}">
<meta name="twitter:description" content="{description}">
<meta name="twitter:image" content="{image URL}">

<!-- Hreflang (gtranslate handles runtime, but add defaults) -->
<link rel="alternate" hreflang="en" href="https://tribalsand.com/{page}.php">
<link rel="alternate" hreflang="x-default" href="https://tribalsand.com/{page}.php">
```

### JSON-LD Schema — Property Pages
```json
{
  "@context": "https://schema.org",
  "@type": "LodgingBusiness",
  "name": "My Amani",
  "description": "...",
  "url": "https://tribalsand.com/my-amani.php",
  "image": ["https://tribalsand.com/images/my-amani/..."],
  "address": {
    "@type": "PostalAddress",
    "addressLocality": "Vipingo",
    "addressRegion": "Kilifi County",
    "addressCountry": "KE"
  },
  "geo": { "@type": "GeoCoordinates", "latitude": -3.82, "longitude": 39.79 },
  "telephone": "+254115115247",
  "email": "reservations@tribalsand.com",
  "priceRange": "$$$",
  "amenityFeature": [...],
  "numberOfRooms": 5,
  "starRating": { "@type": "Rating", "ratingValue": "5" }
}
```

### JSON-LD Schema — Homepage
```json
{
  "@context": "https://schema.org",
  "@type": "Organization",
  "name": "Tribal Sand",
  "url": "https://tribalsand.com",
  "logo": "https://tribalsand.com/images/whitelogo11.png",
  "contactPoint": {
    "@type": "ContactPoint",
    "telephone": "+254-115-115-247",
    "contactType": "reservations",
    "availableLanguage": ["English", "French", "German", "Italian", "Swahili"]
  },
  "sameAs": [
    "https://www.instagram.com/tribalsand/",
    "https://www.facebook.com/tribalsand/",
    "https://www.youtube.com/@tribalsand7436"
  ]
}
```

### Breadcrumb Schema (every page except homepage)
```json
{
  "@context": "https://schema.org",
  "@type": "BreadcrumbList",
  "itemListElement": [
    { "@type": "ListItem", "position": 1, "name": "Home", "item": "https://tribalsand.com/" },
    { "@type": "ListItem", "position": 2, "name": "Properties", "item": "https://tribalsand.com/#properties" },
    { "@type": "ListItem", "position": 3, "name": "My Amani", "item": "https://tribalsand.com/my-amani.php" }
  ]
}
```

---

## 7. IMAGE OPTIMISATION RULES

### Every `<img>` tag must have:
```html
<img
  src="images/my-amani/aerial/myamani-11.webp"
  srcset="images/my-amani/aerial/myamani-11-400.webp 400w,
          images/my-amani/aerial/myamani-11-800.webp 800w,
          images/my-amani/aerial/myamani-11-1200.webp 1200w"
  sizes="(max-width: 768px) 100vw, (max-width: 1200px) 50vw, 800px"
  alt="My Amani beachfront villa aerial view · Vipingo · Kenya"
  width="1200"
  height="800"
  loading="lazy"
  decoding="async"
>
```

### Hero/above-fold images: use `loading="eager"` and add preload:
```html
<link rel="preload" as="image" href="images/my-amani/aerial/myamani-11.webp">
```

### Alt text formula:
`{descriptive content} · {property/location} · {Kenya/Tribal Sand}`

### Image conversion:
- All JPG/PNG → WebP (use PHP's `imagewebp()` or a build script with cwebp)
- Max hero: 1920px wide, 85% quality
- Card images: 800px wide, 82% quality
- Thumbnails: 400px wide, 80% quality
- Always preserve originals as fallback

### PHP helper function:
```php
function ts_img($src, $alt, $width, $height, $sizes='100vw', $lazy=true) {
  $webp = str_replace(['.jpg','.jpeg','.png'], '.webp', $src);
  $loading = $lazy ? 'lazy' : 'eager';
  return "<img src=\"{$webp}\" alt=\"{$alt}\" width=\"{$width}\" height=\"{$height}\" loading=\"{$loading}\" decoding=\"async\" onerror=\"this.src='{$src}'\">";
}
```

---

## 8. PERFORMANCE OPTIMISATION

### .htaccess (create/update)
```apache
# Enable GZIP
<IfModule mod_deflate.c>
  AddOutputFilterByType DEFLATE text/html text/css application/javascript application/json image/svg+xml
</IfModule>

# Browser Caching
<IfModule mod_expires.c>
  ExpiresActive On
  ExpiresByType image/webp "access plus 1 year"
  ExpiresByType image/jpeg "access plus 1 year"
  ExpiresByType text/css "access plus 1 month"
  ExpiresByType application/javascript "access plus 1 month"
  ExpiresByType text/html "access plus 1 day"
</IfModule>

# Security headers
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"

# Redirect HTTP to HTTPS
RewriteEngine On
RewriteCond %{HTTPS} off
RewriteRule ^(.*)$ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]

# www to non-www
RewriteCond %{HTTP_HOST} ^www\.(.*)$ [NC]
RewriteRule ^(.*)$ https://%1/$1 [R=301,L]

# Old URL redirects
Redirect 301 /tribalsand-blog-tribal-dunes.html /tribal-dunes.php
```

### CSS/JS Strategy
- One global `css/main.css` (minified for production)
- No Bootstrap — custom CSS only
- Defer all non-critical JS: `<script defer src="js/main.js"></script>`
- Inline critical CSS in `<head>` for above-fold content
- gtranslate loads deferred

---

## 9. PAGE-BY-PAGE SEO TARGETS

### index.php — Homepage
- **Title:** `Tribal Sand · Luxury Beachfront Hotels & Villas in Kenya`
- **Target keywords:** luxury beachfront villas Kenya, boutique hotels Kilifi, Watamu villas Kenya, Kenyan coast accommodation
- **H1:** `Luxury Beachfront Hotels & Villas on Kenya's North Coast`
- **Schema:** Organization + WebSite + ItemList (properties)
- **Priority:** HIGH — this is the money page

### zuri.php — Zuri Hotel
- **Title:** `Zuri Boutique Hotel · Beachfront Suites · Watamu Kenya · Tribal Sand`
- **Target keywords:** boutique hotel Watamu Kenya, beachfront hotel Watamu, Watamu suites
- **H1:** `Zuri · Luxury Beachfront Boutique Hotel · Watamu`
- **Schema:** LodgingBusiness + BreadcrumbList + FAQPage

### maya-kobe.php — Maya Kobe
- **Title:** `Maya Kobe · Balinese Boutique Hotel · Kilifi Kenya · Tribal Sand`
- **Target keywords:** boutique hotel Kilifi Kenya, beachfront hotel Kilifi, Bofa beach hotel
- **H1:** `Maya Kobe · Beachfront Boutique Hotel · Kilifi`
- **Schema:** LodgingBusiness + BreadcrumbList

### my-amani.php — My Amani
- **Title:** `My Amani · Private Beachfront Villa · Vipingo Kenya · Tribal Sand`
- **Target keywords:** private villa Vipingo Kenya, luxury villa Kilifi County, beachfront villa Kenya
- **H1:** `My Amani · Private Beachfront Villa · Vipingo`
- **Schema:** LodgingBusiness + BreadcrumbList + FAQPage
- **Note:** Redesigned version already built — use tribalsand-my-amani.html as template

### enkare-bofa.php — Enkare Bofa
- **Title:** `Enkare Bofa · Beachfront Villa · Bofa Road Kilifi · Tribal Sand`
- **Target keywords:** villa Bofa Road Kilifi, beachfront villa Kilifi Kenya, self catering villa Kenya
- **H1:** `Enkare Bofa · Beachfront Private Villa · Kilifi`

### sandbox.php — Sandbox Villa
- **Title:** `Sandbox Villa · Beachfront Self-Catering · Kilifi Kenya · Tribal Sand`
- **Target keywords:** self catering villa Kilifi, beachfront villa Kenya affordable, Kilifi holiday villa
- **H1:** `Sandbox · Beachfront Self-Catering Villa · Kilifi`

### maya_ilai.php — Maya Ilai
- **Title:** `Maya Ilai · Eco Retreat Compound · Kilifi Kenya · Tribal Sand`
- **Target keywords:** eco retreat Kilifi Kenya, group accommodation Kenya coast, sustainable hotel Kenya
- **H1:** `Maya Ilai · Eco Retreat Compound · Bofa Beach Kilifi`

### activities.php — Activities
- **Title:** `Activities & Experiences · Kenya Coast · Tribal Sand`
- **Target keywords:** things to do Watamu Kenya, activities Kilifi coast, kite surfing Kenya, snorkelling Watamu
- **H1:** `Experiences on Kenya's North Coast`
- **Schema:** ItemList of activities

### events.php — Events
- **Title:** `Events · Weddings & Celebrations · Tribal Sand Kenya`
- **Target keywords:** beach wedding Kenya, events Kilifi, celebrations Kenyan coast
- **H1:** `Events & Celebrations · Kenya's North Coast`

### tribal-dunes.php — Tribal Dunes (NEW)
- **Title:** `Tribal Dunes · Kilifi's Beachfront Community · Kenya · Tribal Sand`
- **Target keywords:** Tribal Dunes Kilifi, beachfront community Kilifi Kenya, things to do Kilifi
- **H1:** `Kilifi's beachfront community for travellers who want more than a place to sleep.`
- **Note:** Migrate from tribalsand-blog-tribal-dunes.html — this is a priority SEO page

### blog.php — Blog
- **Title:** `Blog · Stories from the Kenyan Coast · Tribal Sand`
- **Schema:** Blog + BreadcrumbList

### sustainability.php — Sustainability
- **Title:** `Sustainability · Solar Power & Ocean Conservation · Tribal Sand Kenya`
- **Target keywords:** sustainable hotel Kenya, eco friendly accommodation Kenya, solar powered hotel Africa
- **H1:** `Sustainability at Tribal Sand`

### contact.php — Contact
- **Title:** `Contact · Reservations · Tribal Sand Kenya`
- **Schema:** LocalBusiness with full address/hours

---

## 10. SITEMAP & ROBOTS

### sitemap.xml (generate dynamically via PHP or static)
```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://tribalsand.com/</loc><priority>1.0</priority><changefreq>weekly</changefreq></url>
  <url><loc>https://tribalsand.com/zuri.php</loc><priority>0.9</priority></url>
  <url><loc>https://tribalsand.com/maya-kobe.php</loc><priority>0.9</priority></url>
  <url><loc>https://tribalsand.com/my-amani.php</loc><priority>0.9</priority></url>
  <url><loc>https://tribalsand.com/enkare-bofa.php</loc><priority>0.8</priority></url>
  <url><loc>https://tribalsand.com/sandbox.php</loc><priority>0.8</priority></url>
  <url><loc>https://tribalsand.com/maya_ilai.php</loc><priority>0.8</priority></url>
  <url><loc>https://tribalsand.com/tribal-dunes.php</loc><priority>0.8</priority></url>
  <url><loc>https://tribalsand.com/activities.php</loc><priority>0.7</priority></url>
  <url><loc>https://tribalsand.com/events.php</loc><priority>0.6</priority></url>
  <url><loc>https://tribalsand.com/sustainability.php</loc><priority>0.6</priority></url>
  <url><loc>https://tribalsand.com/blog.php</loc><priority>0.7</priority></url>
  <url><loc>https://tribalsand.com/contact.php</loc><priority>0.5</priority></url>
  <url><loc>https://tribalsand.com/tribalsandstory.php</loc><priority>0.5</priority></url>
  <!-- Gallery pages — lower priority -->
  <url><loc>https://tribalsand.com/my-amani-gallery.php</loc><priority>0.4</priority></url>
  <url><loc>https://tribalsand.com/maya-kobe-gallery.php</loc><priority>0.4</priority></url>
  <url><loc>https://tribalsand.com/zuri-gallery.php</loc><priority>0.4</priority></url>
  <url><loc>https://tribalsand.com/enkarebofa-gallery.php</loc><priority>0.3</priority></url>
  <url><loc>https://tribalsand.com/sandbox-gallery.php</loc><priority>0.3</priority></div>
  <url><loc>https://tribalsand.com/maya-ilai-gallery.php</loc><priority>0.3</priority></url>
  <url><loc>https://tribalsand.com/events-gallery.php</loc><priority>0.3</priority></url>
</urlset>
```

### robots.txt
```
User-agent: *
Allow: /
Disallow: /includes/
Disallow: /api/
Disallow: /admin/

Sitemap: https://tribalsand.com/sitemap.xml
```

---

## 11. GHL & INTEGRATIONS

### Trip Builder Webhook
- **URL:** `https://services.leadconnectorhq.com/hooks/cBTrngnK5Q4lTkFUwhlo/webhook-trigger/ad7f1a2d-9c2a-4f9a-9049-c30b144643e5`
- **Method:** POST
- **All fields use:** `{{inboundWebhookRequest.fieldName}}`
- **Key fields:** firstName, lastName, email, phone, country, ref, property, arrival, departure, nights, adults, children, purpose, occasions, dietary, summary, note
- **File:** `tribal-sand-trip-builder-v3.html` → migrate to `trip-builder.php`

### gtranslate
Keep exact gtranslate implementation from current header:
```javascript
window.gtranslateSettings = {
  "default_language": "en",
  "languages": ["en","fr","sw","de","hi","it","zh-TW"],
  "wrapper_selector": ".gtranslate_wrapper",
  "flag_size": 14,
  "switcher_horizontal_position": "inline"
};
```
Position: `position:fixed; top:72px; right:12px; z-index:99999`
Add class `scrolled60` when nav scrolls to move to `top:64px`

### LeadConnector Chat Widget
Keep in footer.php:
```html
<script src="https://widgets.leadconnectorhq.com/loader.js"
  data-resources-url="https://widgets.leadconnectorhq.com/chat-widget/loader.js"
  data-widget-id="691f01ab467a1f787a2fa6f9">
</script>
```

### Booking Engine
All "Book Now" buttons → `https://book.tribalsand.com/booking/chain-tribalsand-en`
My Amani specific → `https://book.tribalsand.com/booking/roomwisedata.php?hid=tribalsandlimited&roomtypeunkid=5477100000000000001`

---

## 12. FILES ALREADY BUILT — USE AS TEMPLATES

All files are in the project outputs. Use these as direct templates:

| File | Use as template for |
|------|---------------------|
| `header.php` | `includes/header.php` — drop in directly |
| `footer.php` | `includes/footer.php` — drop in directly |
| `tribalsand-home.html` | `index.php` |
| `tribalsand-my-amani.html` | `my-amani.php` (also template for all other property pages) |
| `tribalsand-blog-tribal-dunes.html` | `tribal-dunes.php` |
| `tribal-sand-trip-builder-v3.html` | `trip-builder.php` |
| `ts-ghl-email-template.html` | GHL email template (paste in HTML mode) |

---

## 13. PROPERTY REFERENCE DATA

| Key | Full Name | Location | Type | Sleeps | Rooms | Notes |
|-----|-----------|----------|------|--------|-------|-------|
| zuri | Zuri | Watamu | Boutique Hotel | 14 | 6 suites | Book suite or full buyout |
| mayakobe | Maya Kobe | Bofa Road, Kilifi | Boutique Hotel | 12 | 5 suites | Balinese-inspired, Tribal Dunes |
| amani | My Amani | Vipingo | Private Villa | 10 | 5 bedrooms | Infinity pool, hot tub, chef |
| enkare | Enkare Bofa | Bofa Road, Kilifi | Private Villa | 10 | 5 bedrooms | In-house cook, Bofa Road |
| sandbox | Sandbox | Bofa Road, Kilifi | Private Villa | 8 | 4 bedrooms | Self-catering |
| maya_ilai | Maya Ilai | Kilifi | Eco Compound | 48+ | 8 villas + 8 studios | Adults 16+, Tribal Dunes |

### Tribal Dunes Venues (all on one Kilifi property)
| Venue | Status | Type |
|-------|--------|------|
| Maya Kobe | Active | Boutique Hotel |
| Maya Ilai | Active | Eco Compound |
| Off Duty | Active | Coworking Hotel |
| Tribal Table | Coming Soon | Restaurant & Bar |
| Somewhere Café | Coming Soon | Beachfront Café |
| Tribal Kite School Kilifi | Active | Ocean Sports |

---

## 14. SEO RANKING STRATEGY

### Pages currently likely ranking (protect these):
- `zuri.php` — "Zuri Watamu" branded
- `maya-kobe.php` — "Maya Kobe Kilifi" branded
- `my-amani.php` — "My Amani Vipingo" branded
- `index.php` — brand name searches

### Pages NOT ranking well (improve these):
- `activities.php` — needs content depth, activity schema
- `events.php` — needs event schema
- `maya_ilai.php` — underscore URL is fine, needs better content
- `enkare-bofa.php` — needs stronger content
- `sandbox.php` — needs stronger content
- `sustainability.php` — great opportunity, currently thin

### New ranking opportunities (create these):
- `tribal-dunes.php` — "Tribal Dunes Kilifi" is uncontested
- `trip-builder.php` — "Kenya coast trip planner" long-tail
- Blog posts for: "things to do in Kilifi", "best beaches Kenya north coast", "kite surfing Kilifi Kenya", "where to stay Watamu Kenya"

### On-page SEO rules for every property page:
1. H1 must contain property name + location + country
2. First 100 words must contain primary keyword naturally
3. At least 300 words of unique descriptive content
4. Internal links to: activities, contact, trip-builder, 2 other properties
5. Image alt text descriptive and keyword-rich
6. FAQ section with schema markup (great for featured snippets)
7. Reviews with structured data

---

## 15. GETTING STARTED IN CLAUDE CODE

### Step 1 — Copy existing files to your project
```bash
# Upload these files from the outputs folder to your working directory:
# - header.php → includes/header.php
# - footer.php → includes/footer.php
# - tribalsand-my-amani.html → reference/my-amani-reference.html
# - tribalsand-home.html → reference/homepage-reference.html
# - tribalsand-blog-tribal-dunes.html → reference/tribal-dunes-reference.html
# - tribal-sand-trip-builder-v3.html → reference/trip-builder-reference.html
# - ts-ghl-email-template.html → reference/email-template-reference.html
```

### Step 2 — First prompt for Claude Code
```
I'm rebuilding tribalsand.com in PHP. I have the brand system, header.php and 
footer.php already built. 

Start by creating:
1. includes/head.php — dynamic SEO head with canonical, OG, JSON-LD schema support
2. includes/schema.php — PHP functions for all JSON-LD types (LodgingBusiness, Organization, BreadcrumbList, FAQPage)
3. css/main.css — global styles using the brand tokens
4. .htaccess — GZIP, caching, HTTPS redirect, www redirect, old URL redirects
5. robots.txt
6. sitemap.xml

Then rebuild index.php using reference/homepage-reference.html as the design template.
Keep ALL existing URLs exactly as they are.
```

### Step 3 — For each property page
```
Rebuild my-amani.php using reference/my-amani-reference.html as design template.
Add full SEO: JSON-LD LodgingBusiness schema, breadcrumbs, proper H1/meta.
Template the layout so it can be reused for all 5 other property pages.
```

---

## 16. IMPORTANT NOTES

1. **gtranslate positioning** — the widget uses `id="gtranslate_wrapper"` and gets class `scrolled60` from JS scroll detection. Keep this exactly.

2. **Bootstrap removal** — current site uses Bootstrap 4+5 (both loaded!). Remove entirely. Use custom CSS only.

3. **scrolled60 class** — the nav scroll JS uses `scrolled60` not `scrolled`. Keep this class name — it also controls gtranslate position.

4. **Security deposit** — USD 500 applies to all villa bookings. Non-smoking properties.

5. **Maya Ilai age policy** — Adults only, guests must be 16+. Important for accuracy.

6. **GHL field mapping** — ALL GHL workflow fields use `{{inboundWebhookRequest.xxx}}` NOT `{{trigger.xxx}}`. This is critical for the trip builder to work.

7. **Trip builder** — currently v3 (tribal-sand-trip-builder-v3.html). 7-step flow. GHL webhook already wired. When migrating to trip-builder.php keep the same step structure.

8. **Image paths** — current site images are at `images/` relative path. Keep this structure. Add WebP versions alongside originals.

9. **Phone number** — always format as `+254 115 115 247` in display, `tel:+254115115247` in href.

10. **Copyright year** — update to 2026.
