# NEXXI Kids WooCommerce theme

This classic PHP theme renders the original nine Stitch HTML screens from `assets/stitch/pages/` with their 72 downloaded images, logo and Tailwind design settings. The source screens load their fonts from Google Fonts. WordPress URLs replace static links. The shop, product, cart and checkout connect to WooCommerce catalog, prices, images, cart and order data.

Install the theme and WooCommerce, then activate `nexxi-kids-commerce` for the 16-size guide and size/color cart metadata. Set up WordPress pages for shop, cart, checkout and account in WooCommerce settings. The Docker preview seed script demonstrates this setup.

The product gallery, marketing copy, endorsements, badges and promotional panels in the Stitch screens are design sample content. Replace them with verified store content before publishing. Configure shipping under **WooCommerce → Settings → Shipping**; the design displays a 299,000₫ free-shipping threshold. Set the hotline, email, address, opening hours and Flash Sale end time in **Appearance → Customize → NEXXI – Thông tin cửa hàng**. Enter the Flash Sale end time as an ISO 8601 time with timezone, for example `2026-10-10T21:00:00+07:00`.

## Architecture

`tools/add-stitch-hooks.ps1` adds `data-nx="…"` hooks to the Stitch HTML. The `inc/stitch-*.php` modules replace those blocks through DOM helpers in `stitch-dom.php`. The theme never matches sample text. `assets/stitch/stitch-interactions.js` handles:

- AJAX add to cart
- variation colour/size selection
- the wishlist: stored in localStorage and synced to `nexxi/v1/wishlist` when the visitor is logged in
- shop filters
- countdowns
- the size advisor

## Pages

Use variable products with `pa_size` and `pa_mau-sac` attributes to get per-size and per-colour price and stock.

These page slugs get the Stitch header and footer around the editor content:

- `gioi-thieu` (about)
- `lien-he` (contact form, map and FAQ)
- `yeu-thich` (wishlist)
- `doi-tra` (returns; `#bao-hanh` anchor)
- `van-chuyen` (shipping)

The contact form sends mail with `wp_mail`, so configure SMTP in production.

## NEXXI Studio

Appearance → Customize contains numbered panels (global, header, home, shop, product, flash sale, login, footer). Each page region is one section with a show/hide toggle and text/image/link/icon fields; the live preview outlines the region being edited, flashes the element of the focused field, and its hover label opens the matching section. Values are stored in the theme mod `nexxi_studio`. Fields are generated from the Stitch HTML by `tools/studio-annotate.php` (writes `inc/studio-fields.php`); runtime lives in `inc/studio.php` and `inc/studio-customizer.php`.
