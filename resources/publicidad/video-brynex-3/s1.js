// ── Escena 1 (0–3,75 s): la EPS encadenada por la pensión ──────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="ctr" style="top:300px;font-size:140px"></div>`, el), l2 = h(`<div class="ctr" style="top:470px;font-size:90px"></div>`, el), l3 = h(`<div class="ctr" style="top:582px;font-size:118px"></div>`, el);
  const w1 = words(l1, '¿Sin EPS'), w2 = words(l2, 'porque la pensión'), w3 = words(l3, 'no te alcanza?', { all: 'amber' });
  const shield = h(`<svg class="lock" viewBox="0 0 400 460"><defs><linearGradient id="sg" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#60a5fa"/><stop offset="1" stop-color="#1d4ed8"/></linearGradient></defs>
    <path d="M200 10 L370 70 V220 C370 330 295 410 200 450 C105 410 30 330 30 220 V70 Z" fill="url(#sg)" stroke="#bfdbfe" stroke-width="8"/>
    <path d="M170 120h60v70h70v60h-70v70h-60v-70h-70v-60h70z" fill="#fff"/></svg>`, el);
  const cL = h(`<div class="chain" style="width:480px"></div>`, el), cR = h(`<div class="chain" style="left:540px;width:480px"></div>`, el);
  const pad = h(`<svg class="abs" width="210" height="250" viewBox="0 0 210 250" style="left:435px;top:880px;transform-origin:50% 60%"><path d="M50 110 V70 a55 55 0 0 1 110 0 V110" fill="none" stroke="#cbd5e1" stroke-width="26"/><rect x="15" y="105" width="180" height="140" rx="30" fill="#ef4444" stroke="#fecaca" stroke-width="6"/><circle cx="105" cy="165" r="20" fill="#7f1d1d"/><rect x="96" y="170" width="18" height="44" rx="8" fill="#7f1d1d"/></svg>`, el);
  scenes.push({ a: 0, b: 3.76, el, draw(t) {
    slam(w1, t, .03, .14); slam(w2, t, .47, .1); slam(w3, t, .94, .12);
    const si = pr(t, .5, .6);
    let bump = 0; HITS.forEach(b => { if (t >= b) bump += Math.exp(-(t - b) * 9); });
    T(shield, { o: pr(t, .5, .15), s: lerp(.5, 1, back(si)) * (1 - bump * .03), y: (1 - eo5(si)) * 200 });
    // Se rompe la cadena y cae el candado.
    const br = eo3(pr(t, 3.28, .45)), ci = eo5(pr(t, 1.2, .5));
    T(cL, { o: pr(t, 1.2, .1) * (1 - pr(t, 3.55, .2)), x: lerp(-600, 0, ci) - br * 700, r: -br * 25, y: br * 120 });
    T(cR, { o: pr(t, 1.2, .1) * (1 - pr(t, 3.55, .2)), x: lerp(600, 0, ci) + br * 700, r: br * 25, y: br * 120 });
    const pi = pr(t, 1.5, .4), fall = ei3(pr(t, 3.28, .47));
    T(pad, { o: pr(t, 1.5, .1) * (1 - pr(t, 3.6, .15)), s: lerp(2, 1, back(pi)) + bump * .14, y: fall * 1000, r: Math.sin(t * 40) * bump * 8 + fall * 70 });
    pad.style.filter = `drop-shadow(0 0 ${20 + bump * 60}px rgba(239,68,68,${.4 + bump * .5}))`;
    const out = ei3(pr(t, 3.55, .2));
    [l1, l2, l3].forEach(x => { x.style.opacity = 1 - out; });
    shield.style.opacity = (1 - out) * pr(t, .5, .15);
  } });
})();
