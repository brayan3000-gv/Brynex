// ── Escena 5 (20,63–24,38 s): la garantía de cada mes ──────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const glow = h(`<div class="abs" style="left:190px;top:200px;width:700px;height:700px;border-radius:50%;background:radial-gradient(closest-side,rgba(251,191,36,.45),transparent);filter:blur(30px)"></div>`, el);
  const seal = h(`<svg class="seal" viewBox="0 0 500 500"><defs>
      <linearGradient id="gd" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#fde68a"/><stop offset=".55" stop-color="#fbbf24"/><stop offset="1" stop-color="#d97706"/></linearGradient>
      <path id="ring" d="M250 250 m-178 0 a178 178 0 1 1 356 0 a178 178 0 1 1 -356 0"/></defs>
    <g id="sr"><path d="${Array.from({ length: 36 }, (_, i) => { const a = i * Math.PI / 18, r = i % 2 ? 228 : 248; return (i ? 'L' : 'M') + (250 + Math.cos(a) * r).toFixed(1) + ' ' + (250 + Math.sin(a) * r).toFixed(1); }).join(' ')}Z" fill="url(#gd)"/>
    <circle cx="250" cy="250" r="205" fill="none" stroke="#0a1628" stroke-width="4" opacity=".35"/>
    <text font-family="P" font-weight="700" font-size="40" fill="#0a1628"><textPath href="#ring" textLength="1100" lengthAdjust="spacing">GARANTÍA BRYGAR · TODOS LOS MESES ·</textPath></text></g>
    <circle cx="250" cy="250" r="140" fill="#0a1628"/>
    <text x="250" y="262" text-anchor="middle" font-family="P" font-weight="700" font-size="104" fill="#fbbf24">100%</text>
    <text x="250" y="316" text-anchor="middle" font-family="P" font-weight="600" font-size="30" fill="#fde68a" letter-spacing="3">DEVOLUCIÓN</text></svg>`, el);
  const sr = seal.querySelector('#sr');
  const a1 = h(`<div class="ctr" style="top:880px;font-size:62px"></div>`, el), a2 = h(`<div class="ctr" style="top:960px;font-size:62px"></div>`, el);
  const b1 = h(`<div class="ctr" style="top:1062px;font-size:80px"></div>`, el), b2 = h(`<div class="ctr" style="top:1172px;font-size:54px"></div>`, el);
  const c1 = h(`<div class="ctr" style="top:1262px;font-size:38px;color:#93c5fd;font-weight:600">Estando al día · Todos los meses</div>`, el);
  const wa1 = words(a1, 'Si tu EPS no te atiende'), wa2 = words(a2, 'por tu afiliación,'), wb1 = words(b1, 'te devolvemos el 100 %', { all: 'amber' }), wb2 = words(b2, 'del mes y te reactivamos gratis.');
  scenes.push({ a: 20.625, b: 24.39, el, draw(t) {
    const k = pr(t, 20.66, .55);
    T(seal, { o: pr(t, 20.66, .08), s: lerp(2.4, 1, back(k)), r: lerp(-40, 0, eo5(k)), blur: (1 - eo5(k)) * 10 });
    sr.setAttribute('transform', `rotate(${t * 18} 250 250)`);
    T(glow, { o: pr(t, 20.9, .4) * (.7 + .3 * Math.sin(t * 3)), s: 1 + (t >= 20.9 ? Math.exp(-(t - 20.9) * 5) * .5 : 0) });
    slam(wa1, t, 21.5625, .08); slam(wa2, t, 21.85, .08); slam(wb1, t, 22.5, .1); slam(wb2, t, 22.96875, .07);
    pop(c1, t, 23.4375, undefined, { s0: .7, y0: 20, d: .35 });
    const out = ei3(pr(t, 24.15, .23));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 180}px)`;
  } });
})();

// ── Escena 6 (24,38–30 s): el cierre ───────────────────────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const halo = h(`<div class="abs" style="left:90px;top:300px;width:900px;height:640px;border-radius:50%;background:radial-gradient(closest-side,rgba(59,130,246,.55),transparent);filter:blur(30px)"></div>`, el);
  const logo = h(`<img class="abs" src="logo-brygar.png" style="left:110px;top:455px;width:860px;transform-origin:50% 50%">`, el);
  const a = h(`<div class="ctr" style="top:780px;font-size:82px"></div>`, el), b = h(`<div class="ctr tn" style="top:880px;font-size:122px"></div>`, el);
  const wa1 = words(a, 'EPS sin pensión', { all: 'grad' }), wb = words(b, '$125.000 al mes');
  wb[1].style.fontSize = wb[2].style.fontSize = '74px'; wb[1].style.color = wb[2].style.color = '#bfdbfe';
  const small = h(`<div class="ctr" style="top:1030px;font-size:40px;color:#bfdbfe;font-weight:600">Todo incluido · La afiliación también</div>`, el);
  const rings = [0, 1].map(() => h(`<div class="ring" style="top:1146px"></div>`, el));
  const wa = h(`<div id="wa" style="top:1146px"><svg width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"><path d="M32 8a24 24 0 0 0-20.6 36.3L8 56l12-3.3A24 24 0 1 0 32 8z"/><path d="M24 26c0 8 6 14 14 14"/></svg>Escríbenos por WhatsApp</div>`, el);
  const sub = h(`<div class="ctr" style="top:1338px;font-size:33px;font-weight:600;color:#93c5fd;letter-spacing:.04em">Médico · Medicamentos · Tus beneficiarios</div>`, el);
  const T0 = 24.375, P = BT * 2;
  scenes.push({ a: T0, b: 30.01, el, draw(t) {
    const k = pr(t, T0 + .02, .7);
    T(logo, { o: pr(t, T0 + .02, .12), s: lerp(2, 1, eo5(k)) + Math.sin(t * 1.4) * .008, blur: (1 - k) * 18 });
    T(halo, { o: pr(t, T0 + .1, .6) * (.75 + .25 * Math.sin(t * 2.2)), s: 1 + Math.sin(t * 1.1) * .05 });
    slam(wa1, t, T0 + BT * 2, .1); slam(wb, t, T0 + BT * 3, .09);
    pop(small, t, T0 + BT * 3.6, undefined, { s0: .7, y0: 20, d: .35 });
    pop(wa, t, T0 + BAR + BT, undefined, { s0: .4, y0: 90, d: .55 });
    const s0 = T0 + BAR + BT + .45;
    wa.style.transform += ` scale(${1 + Math.max(0, Math.sin((t - s0) * Math.PI * 2 / P)) * .035 * pr(t, s0, .3)})`;
    rings.forEach((r, i) => { const st = s0 + i * P / 2, c = (((t - st) % P) + P) % P / P; T(r, { o: t > st ? (1 - c) * .7 : 0, s: 1 + c * .22 }); });
    T(sub, { o: pr(t, s0 + .2, .5), y: (1 - eo5(pr(t, s0 + .2, .6))) * 30 });
  } });
})();
