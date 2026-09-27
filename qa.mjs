import puppeteer from 'puppeteer';
const sizes = (process.argv[2] ? JSON.parse(process.argv[2]) : [[320,568],[360,640],[360,800],[375,667],[375,812],[390,844],[393,852],[412,915],[414,896],[430,932],[768,1024],[820,1180],[667,375],[844,390],[1024,768],[1280,800],[1440,900],[1920,1080]]);
const shots = process.argv[3] ? JSON.parse(process.argv[3]) : [];
const b = await puppeteer.launch({headless:true,args:['--no-sandbox']});
const p = await b.newPage();
let total = 0;
for (const [w,h] of sizes) {
  await p.setViewport({width:w,height:h});
  await p.goto('http://localhost:3011',{waitUntil:'networkidle2'});
  await p.addStyleTag({content:'*,*::before,*::after{animation:none!important;transition:none!important}'});
  await p.evaluate(()=>{document.querySelectorAll('img').forEach(i=>i.loading='eager');document.querySelectorAll('.reveal,.reveal-img,.wordmark').forEach(e=>e.classList.add('visible'));});
  await new Promise(r=>setTimeout(r,1200));
  const res = await p.evaluate(() => {
    const out = {overflow: document.documentElement.scrollWidth - innerWidth, orphans: [], overlaps: [], offscreen: []};
    const vis = el => { const s = getComputedStyle(el); const r = el.getBoundingClientRect(); return s.display !== 'none' && s.visibility !== 'hidden' && r.width > 0 && r.height > 0 && !el.closest('[aria-hidden="true"]') && !el.closest('.drawer') && !el.closest('details:not([open]) > :not(summary)'); };
    const TEXT = 'p, h1, h2, h3, li, summary, label, a.btn, button.btn, .chip, .cred, .stat__label, .stat__note, .floating-tag, .contact-pill > span, .product__docs, .promo a, .footer__bottom > span, .lede, .eyebrow, .trust__logo';
    const els = [...document.querySelectorAll(TEXT)].filter(vis);
    const label = el => (el.className && typeof el.className === 'string' ? '.'+el.className.split(' ')[0] : el.tagName.toLowerCase()) + ' "' + el.textContent.trim().replace(/\s+/g,' ').slice(0,50) + '"';
    // single-word last line
    for (const el of els) {
      const words = [];
      const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
      let n; while ((n = walker.nextNode())) {
        if (n.parentElement.closest('svg')) continue;
        const re = /[^\s ]+/g; let m;
        while ((m = re.exec(n.data))) if (/[\p{L}\p{N}]/u.test(m[0])) words.push([n, m.index, m.index + m[0].length]);
      }
      if (words.length < 3) continue;
      const rect = ([node, a, z]) => { const r = document.createRange(); r.setStart(node, a); r.setEnd(node, z); const rs = r.getClientRects(); return rs[rs.length-1]; };
      const last = rect(words[words.length-1]), prev = rect(words[words.length-2]);
      if (!last || !prev) continue;
      const lines = new Set(words.map(w => Math.round(rect(w)?.top ?? 0))).size;
      if (lines > 1 && last.top > prev.top + 4) out.orphans.push(label(el));
    }
    // overlapping text boxes (ignore ancestor/descendant pairs and fixed UI)
    const leaf = els.filter(el => !els.some(o => o !== el && el.contains(o)) && !el.closest('.nav-shell, .wa, .marquee'));
    const R = leaf.map(el => el.getBoundingClientRect());
    for (let i = 0; i < leaf.length; i++) for (let j = i+1; j < leaf.length; j++) {
      const a = R[i], c = R[j];
      const ix = Math.min(a.right, c.right) - Math.max(a.left, c.left), iy = Math.min(a.bottom, c.bottom) - Math.max(a.top, c.top);
      if (ix > 2 && iy > 2) out.overlaps.push(label(leaf[i]) + '  X  ' + label(leaf[j]));
    }
    // content spilling outside the viewport horizontally
    for (const el of leaf) { const r = el.getBoundingClientRect(); if ((r.right > innerWidth + 1 || r.left < -1) && !el.closest('.marquee')) out.offscreen.push(label(el) + ` [${Math.round(r.left)}..${Math.round(r.right)}]`); }
    // content under the fixed nav at page top (hero headline)
    const nav = document.querySelector('.nav').getBoundingClientRect(), h1 = document.querySelector('.hero .display').getBoundingClientRect();
    if (h1.top < nav.bottom) out.overlaps.push('hero headline under nav');
    const proof = document.querySelector('.hero__proof'), hero = document.querySelector('.hero').getBoundingClientRect();
    if (getComputedStyle(proof).display !== 'none' && proof.getBoundingClientRect().bottom > hero.bottom + 1) out.overlaps.push('hero chips cut off');
    return out;
  });
  const n = res.orphans.length + res.overlaps.length + res.offscreen.length + (res.overflow > 0 ? 1 : 0);
  total += n;
  console.log(`\n${w}x${h}: ${n ? n + ' issue(s)' : 'OK'}${res.overflow > 0 ? '  H-SCROLL +' + res.overflow + 'px' : ''}`);
  for (const k of ['orphans','overlaps','offscreen']) for (const x of res[k]) console.log(`  [${k}] ${x}`);
  if (shots.some(([sw,sh]) => sw===w && sh===h)) await p.screenshot({path:`temporary screenshots/qa-${w}x${h}.png`, fullPage:true});
}
console.log('\nTOTAL ISSUES:', total);
await b.close();
