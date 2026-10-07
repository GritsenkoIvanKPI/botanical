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
