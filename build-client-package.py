#!/usr/bin/env python3
"""Build the ready-to-upload package for the client's own server.

Copies the site into client-package/public_html/, switches every absolute URL
from the preview address to the production domain, adds the guide, and zips it.

    python3 build-client-package.py
"""
import os, re, shutil, zipfile, datetime

PREVIEW = 'https://botanical-liart.vercel.app'
DOMAIN = 'https://botanicalextracts.pro'
OUT = 'client-package'
SITE = os.path.join(OUT, 'public_html')
ZIP = 'botanical-extracts-pro-site.zip'

FILES = ['index.html', 'send-form.php', 'config.example.php', '.htaccess', 'robots.txt', 'sitemap.xml',
         'site.webmanifest', 'og-image.jpg', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png',
         'icon-192.png', 'icon-512.png']
TEXT = {'index.html', 'robots.txt', 'sitemap.xml'}

shutil.rmtree(OUT, ignore_errors=True)
os.makedirs(os.path.join(SITE, 'images'))

index = open('index.html', encoding='utf-8').read()
used = sorted(set(re.findall(r'images/([a-z0-9-]+\.jpg)', index)))
for name in used:
    shutil.copy2(os.path.join('images', name), os.path.join(SITE, 'images', name))

for name in FILES:
    if name in TEXT:
        text = open(name, encoding='utf-8').read().replace(PREVIEW, DOMAIN)
        if name == 'sitemap.xml':
            text = re.sub(r'<lastmod>.*?</lastmod>', f'<lastmod>{datetime.date.today().isoformat()}</lastmod>', text)
        open(os.path.join(SITE, name), 'w', encoding='utf-8').write(text)
    else:
        shutil.copy2(name, os.path.join(SITE, name))

shutil.copy2('TELEGRAM_SETUP.md', os.path.join(OUT, 'ІНСТРУКЦІЯ.md'))

left = sum(open(os.path.join(SITE, n), encoding='utf-8').read().count('vercel.app') for n in TEXT)
assert left == 0, 'preview address still present'
assert not os.path.exists(os.path.join(SITE, 'config.php')), 'config.php must never be packaged'

if os.path.exists(ZIP):
    os.remove(ZIP)
with zipfile.ZipFile(ZIP, 'w', zipfile.ZIP_DEFLATED) as z:
    for root, _, names in os.walk(OUT):
        for n in sorted(names):
            if n == '.DS_Store':
                continue
            full = os.path.join(root, n)
            z.write(full, os.path.relpath(full, OUT))

count = sum(len(f) for _, _, f in os.walk(OUT))
print(f'{OUT}/ built: {count} files, {len(used)} images, domain {DOMAIN}')
print(f'{ZIP}: {os.path.getsize(ZIP) // 1024} KB')
