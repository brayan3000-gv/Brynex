// Uso:  node render.mjs --at 1.5,3,5 [--out dir]      → cuadros sueltos para revisar
//       node render.mjs --frames dir [--fps 30] [--from 0 --to 30]  → todos los cuadros
import { chromium } from 'playwright';
import { fileURLToPath } from 'url';
import path from 'path'; import fs from 'fs';
const arg = (k, d) => { const i = process.argv.indexOf('--' + k); return i > 0 ? process.argv[i + 1] : d; };
const here = path.dirname(fileURLToPath(import.meta.url));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1080, height: 1920 }, deviceScaleFactor: 1 });
page.on('pageerror', e => console.error('ERROR de página:', e.message));
await page.goto('file://' + path.join(here, 'index.html'));
await page.evaluate(() => document.fonts.ready);
const shot = async (t, file) => { await page.evaluate(t => window.render(t), t); await page.screenshot({ path: file, type: 'jpeg', quality: 96 }); };
if (arg('at')) {
  const out = arg('out', '.'); fs.mkdirSync(out, { recursive: true });
  for (const t of arg('at').split(',').map(Number)) await shot(t, path.join(out, `t${t.toFixed(2)}.jpg`));
} else {
  const dir = arg('frames'), fps = +arg('fps', 30), from = +arg('from', 0), to = +arg('to', 30);
  fs.mkdirSync(dir, { recursive: true });
  const n0 = Math.round(from * fps), n1 = Math.round(to * fps);
  for (let n = n0; n < n1; n++) { await shot(n / fps, path.join(dir, `f${String(n).padStart(5, '0')}.jpg`)); if (n % 90 === 0) console.log(`cuadro ${n}/${n1}`); }
}
await browser.close();
