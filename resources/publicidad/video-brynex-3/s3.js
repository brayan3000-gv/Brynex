// ── Escena 3 (9,38–15 s): lo que cubre ─────────────────────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="ctr" style="top:280px;font-size:120px"></div>`, el);
  const w1 = words(l1, 'Te cubre:', { 1: 'grad' });
  const ico = p => `<svg width="80" height="80" viewBox="0 0 44 44" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
  const D = [
    ['#2563eb', '<path d="M10 5v10a8 8 0 0 0 16 0V5"/><path d="M18 23v5a8 8 0 0 0 16 0v-4"/><circle cx="34" cy="20" r="4"/>', 'Médico<br>y citas'],
    ['#8b5cf6', '<rect x="6" y="15" width="32" height="14" rx="7" transform="rotate(-35 22 22)"/><path d="M17 13l10 16"/>', 'Medicamentos'],
    ['#10b981', '<circle cx="15" cy="12" r="5"/><circle cx="30" cy="12" r="5"/><path d="M5 36c1-7 5-11 10-11s9 4 10 11M21 36c1-7 4-11 9-11s8 4 9 11"/>', 'Tus hijos<br>y tu pareja'],
    ['#f59e0b', '<path d="M8 28a14 14 0 0 1 28 0"/><path d="M5 28h34v5H5z"/><path d="M22 10v8"/>', 'ARL<br>incluida'],
  ].map(([c, p, txt], i) => ({ el: h(`<div class="tile" style="left:${i % 2 ? 560 : 80}px;top:${i < 2 ? 470 : 880}px"><div class="ic" style="background:${c}">${ico(p)}</div><div>${txt}</div></div>`, el), at: 9.84375 + i * BT }));
  const l2 = h(`<div class="ctr" style="top:1320px;font-size:72px"></div>`, el);
  const w2 = words(l2, 'Todo por $125.000 al mes.', { 2: 'grad' });
  scenes.push({ a: 9.375, b: 15.01, el, draw(t) {
    slam(w1, t, 9.42, .14);
    D.forEach((d, i) => {
      const q = pr(t, d.at, .5), beat = t > 12.1875 ? Math.max(0, Math.sin((t - 12.1875) * Math.PI * 2 / (BT * 2) - i * .8)) * .04 : 0;
      T(d.el, { o: pr(t, d.at, .1), s: lerp(.4, 1, back(q)) + beat, r: (1 - eo5(q)) * (i % 2 ? 14 : -14), y: (1 - eo5(q)) * 160, blur: (1 - eo5(q)) * 8 });
    });
    slam(w2, t, 12.65625, .1);
    const out = ei3(pr(t, 14.78, .22));
    el.style.opacity = 1 - out; el.style.transform = `scale(${1 - out * .08})`;
  } });
})();
