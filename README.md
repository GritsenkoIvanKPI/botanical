# Botanical Extracts Pro

B2B website for Botanical Extracts Pro, a producer and supplier of natural raw materials for perfumery, cosmetics and aromatherapy: essential oils, absolutes, CO₂ extracts, concretes, hydrolats and plant waxes, with custom production and sourcing.

The site is a single static page: `index.html` plus the photos in `images/`. No build step is needed.

## Run locally

```bash
npm install          # only needed for the screenshot and QA scripts
node serve.mjs       # serves the site at http://localhost:3011
```

## Scripts

- `node screenshot.mjs http://localhost:3011 [label]`: full-page screenshot saved to `temporary screenshots/`
- `node qa.mjs`: checks 18 screen sizes for single-word lines, overlapping text, content spilling off-screen and horizontal scrolling

## Notes

- The quote form opens the visitor's email app with a prefilled request to the company address. It has no server backend yet.
- Partner names are shown as text until the real logo files are available.

## SEO

- Meta tags, Open Graph / Twitter tags and JSON-LD structured data (Organization, WebSite, FAQPage) are in the `<head>` of `index.html`.
- `og-image.jpg` is the 1200×630 share image. Icons: `favicon.ico`, `favicon.svg`, `apple-touch-icon.png`, `icon-192.png`, `icon-512.png`, `site.webmanifest`.
- `robots.txt` and `sitemap.xml` sit in the site root.
- **Changing the domain:** canonical, Open Graph and structured-data URLs use `https://botanical-liart.vercel.app`. When the site moves to its own domain, replace that address in `index.html`, `robots.txt` and `sitemap.xml`.
- If the FAQ text changes, update the matching `FAQPage` block in the `<head>` so Google sees the same answers.

## Request form → Telegram

- The form posts to `send-form.php`, which forwards the request to a Telegram chat. The bot token lives in `config.php` on the server only (never in git; `.htaccess` blocks it from the browser).
- `config.example.php` is the template for `config.php`. `TELEGRAM_SETUP.md` is the step-by-step guide for the client (in Ukrainian).
- On a host without PHP (such as the Vercel preview) the form falls back to opening the visitor's email app.
- `python3 build-client-package.py` builds `client-package/` and `botanical-extracts-pro-site.zip`: the site with all URLs switched to `https://botanicalextracts.pro`, ready to upload to the client's hosting. Both are git-ignored.
- Local test with PHP: `docker run --rm -v "$PWD":/app -w /app -p 8080:8080 php:8.2-cli php -S 0.0.0.0:8080` (with a temporary `config.php` next to `index.html`).

