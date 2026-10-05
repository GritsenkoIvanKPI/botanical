import puppeteer from 'puppeteer';
const b = await puppeteer.launch({headless:true,args:['--no-sandbox']});
const modes = [['fallback fonts', true, ''], ['wider text +6%', false, '*{letter-spacing:.03em!important}'], ['fallback + wider', true, '*{letter-spacing:.03em!important}']];
let total=0;
for (const [name, block, css] of modes) {
  const p = await b.newPage();
  if (block) { await p.setRequestInterception(true); p.on('request', r => /fonts\.(googleapis|gstatic)\.com/.test(r.url()) ? r.abort() : r.continue()); }
  for (const [w,h] of [[320,568],[360,740],[375,667],[390,844],[412,915],[430,932]]) {
    await p.setViewport({width:w,height:h,isMobile:true,hasTouch:true,deviceScaleFactor:2});
    await p.goto('http://localhost:3011',{waitUntil:'networkidle2'}).catch(()=>{});
    await p.addStyleTag({content:'html,body{overflow-x:visible!important}*{animation:none!important;transition:none!important}'+css});
    await p.evaluate(()=>document.querySelectorAll('.reveal,.reveal-img,.wordmark').forEach(e=>e.classList.add('visible')));
    await new Promise(r=>setTimeout(r,700));
    const res = await p.evaluate(()=>{
      const W = document.documentElement.clientWidth; const out=[];
      const lab = e=>`${e.tagName.toLowerCase()}.${(e.className.baseVal??e.className).toString().split(' ')[0]} "${e.textContent.trim().replace(/\s+/g,' ').slice(0,34)}"`;
      // 1) anything that would widen the page
      document.querySelectorAll('body *').forEach(e=>{ const r=e.getBoundingClientRect(); if(!r.width||!r.height||r.right<=W+0.5) return;
        for (let a=e.parentElement; a && a!==document.body; a=a.parentElement){ const s=getComputedStyle(a); if (/(hidden|clip|auto|scroll)/.test(s.overflowX) && a.getBoundingClientRect().right<=W+0.5) return; }
        if (getComputedStyle(e).position==='fixed' && !e.closest('.wa')) return; out.push('PAGE-WIDER '+lab(e)+` right=${Math.round(r.right)}`); });
      // 2) text clipped or spilling out of its box
      document.querySelectorAll('p,li,h1,h2,h3,a,button,summary,label,span.cred,.chip,.badge,.eyebrow,.contact-pill,.wholesale__email').forEach(e=>{ if(!e.offsetParent||e.closest('.marquee,.wordmark,.drawer,dialog')) return; const rg=document.createRange(); rg.selectNodeContents(e); const t=rg.getBoundingClientRect(), r=e.getBoundingClientRect(); if(!t.width) return;
        if (t.right>r.right+2 || t.right>W-2) out.push('TEXT-SPILL '+lab(e)+` text=${Math.round(t.right)} box=${Math.round(r.right)}`); });
      return [...new Set(out)];
    });
    total+=res.length;
    console.log(`[${name}] ${w}px:`, res.length?'':'OK'); res.slice(0,14).forEach(x=>console.log('    '+x));
  }
  await p.close();
}
console.log('\nTOTAL', total);
await b.close();
