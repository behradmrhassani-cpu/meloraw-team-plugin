// Real-browser check of the panel's control states (needs playwright; run locally: node tests/css-states.js).
// Loads tests/style-sample.html (WordPress' own :hover/:focus rules are simulated in it), puts every control into
// normal / hover / focus / active / disabled and reads the computed text and the effective background. Fails when the
// contrast is below 4.5 or near-black text sits on a dark surface.
const { chromium } = require('playwright');
const path = require('path');

const lum = (c) => { const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }; return 0.2126 * f(c[0]) + 0.7152 * f(c[1]) + 0.0722 * f(c[2]); };
const ratio = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((p, q) => q - p); return (x + 0.05) / (y + 0.05); };
const parse = (s) => { const m = s.match(/rgba?\(([^)]+)\)/); if (!m) return null; const p = m[1].split(',').map(Number); return { rgb: p.slice(0, 3), a: p.length > 3 ? p[3] : 1 }; };

(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1100, height: 1000 } })).newPage();
  await page.goto('file://' + path.resolve(__dirname, 'style-sample.html'));
  const read = (sel) => page.$eval(sel, (el) => {
    const cs = getComputedStyle(el); let bg = null, n = el;
    const layers = [];
    while (n && n.nodeType === 1) { layers.push(getComputedStyle(n).backgroundColor); n = n.parentElement; }
    return { color: cs.color, layers };
  });
  const effectiveBg = (layers) => { // composite from the nearest opaque layer outwards
    let base = [11, 12, 14];
    for (const l of layers.reverse()) { const p = parse(l); if (p && p.a > 0) base = base.map((b, i) => Math.round(p.rgb[i] * p.a + b * (1 - p.a))); }
    return base;
  };
  let fail = 0, n = 0;
  const ctls = await page.$$eval('[data-ctl]', (els) => els.map((e) => e.dataset.ctl));
  for (const ctl of ctls) {
    const sel = `[data-ctl="${ctl}"]`;
    const disabled = await page.$eval(sel, (e) => e.disabled === true);
    const states = disabled ? ['disabled'] : ['normal', 'hover', 'focus', 'active'];
    for (const st of states) {
      await page.mouse.move(0, 0); await page.evaluate(() => document.activeElement && document.activeElement.blur());
      if (st === 'hover') await page.hover(sel);
      if (st === 'focus') await page.focus(sel);
      if (st === 'active') { await page.hover(sel); await page.mouse.down(); }
      const { color, layers } = await read(sel);
      if (st === 'active') await page.mouse.up();
      const text = parse(color); const bg = effectiveBg(layers);
      const r = ratio(text.rgb, bg); const black = lum(text.rgb) < 0.02 && lum(bg) < 0.1; // near-black text on a dark surface (dark text on the gold button is fine)
      const ok = r >= 4.5 && !black; n++; fail += ok ? 0 : 1;
      console.log(`${ok ? '  ✓' : '  ✗'} ${ctl.padEnd(18)} ${st.padEnd(9)} text ${color.padEnd(20)} on rgb(${bg}) = ${r.toFixed(1)}${ok ? '' : '  <-- unreadable'}`);
    }
  }
  await browser.close();
  console.log(fail ? `\n${fail} of ${n} states unreadable` : `\nall ${n} states readable`);
  process.exit(fail ? 1 : 0);
})();
