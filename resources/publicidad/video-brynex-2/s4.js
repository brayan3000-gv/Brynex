// ── Escena 4 (18,75–24,38 s): tres niveles de ayuda, como un medidor que sube ───
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="line" style="top:284px;font-size:132px"></div>`, el), l2 = h(`<div class="line" style="top:440px;font-size:80px"></div>`, el);
  const w1 = words(l1, 'Tú eliges'), w2 = words(l2, 'cuánto te ayudamos', { all: 'grad' });
  const track = h(`<div id="track"><div id="fill"></div></div>`, el), fill = track.querySelector('#fill');
  const Y = [1390, 1090, 790], AT = [19.6875, 20.625, 21.5625], H = [90, 390, 690];
  const L = [['Solo el programa', 'Tu equipo trabaja en BryNex.'], ['Razones sociales', 'Afilias bajo nuestras empresas.'], ['Todo lo operativo', 'Hacemos los trámites por ti.']].map(([a, b], i) => {
    const node = h(`<div class="node" style="top:${Y[i] - 64}px"><u></u><span>${i + 1}</span></div>`, el);
    return { node, u: node.querySelector('u'), lv: h(`<div class="lv" style="top:${Y[i] - 68}px"><b>${a}</b><p>${b}</p></div>`, el) };
  });
  const foot = h(`<div class="ctr" style="top:1512px;font-size:60px"></div>`, el);
  const fw = words(foot, 'Según tus afiliados.', { 1: 'grad', 2: 'grad' });
  scenes.push({ a: 18.75, b: 24.39, el, draw(t) {
    slam(w1, t, 18.85, .12); slam(w2, t, 19.12, .08);
    const tr = eo5(pr(t, 18.9, .6));
    track.style.opacity = tr; track.style.transform = `scaleY(${tr})`; track.style.transformOrigin = '50% 100%';
    let fh = 0; H.forEach((hh, i) => { fh = lerp(fh, hh, eio(pr(t, AT[i] - .5, .5))); });
    fh = lerp(fh, 790, eo3(pr(t, 22.5, .5)));
    fill.style.height = fh + 'px';
    L.forEach((o, i) => {
      const on = t >= AT[i], d = t - AT[i];
      const beat = t >= 22.5 ? Math.max(0, Math.sin((t - 22.5) * Math.PI * 2 / BT - i * 1.1)) * .07 : 0;
      T(o.node, { o: pr(t, 19.0 + i * .12, .25) * (on ? 1 : .55), s: (on ? 1 + .4 * Math.exp(-d * 7) : .82) + beat });
      o.u.style.opacity = pr(t, AT[i], .1);
      const q = pr(t, AT[i], .5);
      T(o.lv, { o: pr(t, AT[i], .12), x: (1 - eo5(q)) * 110, blur: (1 - eo5(q)) * 8 });
    });
    slam(fw, t, 22.5, .1);
    const out = ei3(pr(t, 24.0, .36));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 200}px)`;
  } });
})();

// ── Escena 5 (24,38–30 s): el cierre ───────────────────────────────────────────
(() => {
  const el = h(`<div class="scene" style="top:110px"></div>`);
  const dots = Array.from({ length: 34 }, (_, i) => ({ el: h(`<div class="abs" style="width:${6 + rnd(i) * 10}px;height:${6 + rnd(i) * 10}px;border-radius:50%;background:#93c5fd"></div>`, el), x: rnd(i + 70) * 1080, v: 60 + rnd(i + 20) * 150, ph: rnd(i + 5) * 1920 }));
  const halo = h(`<div class="abs" style="left:90px;top:270px;width:900px;height:700px;border-radius:50%;background:radial-gradient(closest-side,rgba(59,130,246,.55),transparent);filter:blur(30px)"></div>`, el);
  // El sol y la luna dan la vuelta al logo: de día y de noche.
  const orbit = h(`<div class="abs" style="left:20px;top:424px;width:1040px;height:340px;border-radius:50%;border:3px solid rgba(147,197,253,.22)"></div>`, el);
  const sunB = h(`<div class="abs" style="width:54px;height:54px;border-radius:50%;background:#fbbf24;box-shadow:0 0 50px 14px rgba(251,191,36,.6)"></div>`, el);
  const moonB = h(`<div class="abs" style="width:44px;height:44px;border-radius:50%;background:#e0e7ff;box-shadow:0 0 44px 12px rgba(199,210,254,.55)"></div>`, el);
  const logo = h(`<img class="abs" src="../../../public/img/logo-brynex.png" style="left:110px;top:440px;width:860px;transform-origin:50% 50%">`, el);
  const a = h(`<div class="ctr" style="top:850px;font-size:96px"></div>`, el), b = h(`<div class="ctr" style="top:968px;font-size:88px"></div>`, el);
  const wa1 = words(a, 'Somos tu aliado,'), wb = words(b, 'de día y de noche.', { all: 'grad' });
  const rings = [0, 1].map(() => h(`<div class="ring" style="top:1176px"></div>`, el));
  const wa = h(`<div id="wa" style="top:1176px"><svg width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"><path d="M32 8a24 24 0 0 0-20.6 36.3L8 56l12-3.3A24 24 0 1 0 32 8z"/><path d="M24 26c0 8 6 14 14 14" /></svg>Escríbenos por WhatsApp</div>`, el);
  const sub = h(`<div class="ctr" style="top:1372px;font-size:31px;font-weight:600;color:#93c5fd;letter-spacing:.04em">Solo programa · Razones sociales · Todo lo operativo</div>`, el);
  const T0 = 24.375, P = BT * 2;
  scenes.push({ a: T0, b: 30.01, el, draw(t) {
    dots.forEach(d => { const y = 1920 - ((t * d.v + d.ph) % 2100); T(d.el, { o: .1 + .3 * Math.abs(Math.sin(t * 1.3 + d.ph)), x: d.x + Math.sin(t + d.ph) * 30, y }); });
    const k = pr(t, T0 + .02, .7);
    T(logo, { o: pr(t, T0 + .02, .12), s: lerp(2, 1, eo5(k)) + Math.sin(t * 1.4) * .008, blur: (1 - k) * 18 });
    T(halo, { o: pr(t, T0 + .1, .6) * (.75 + .25 * Math.sin(t * 2.2)), s: 1 + Math.sin(t * 1.1) * .05 });
    const oo = pr(t, T0 + .5, .6);
    T(orbit, { o: oo, r: -10, s: lerp(1.3, 1, eo5(oo)) });
    [[sunB, 0, 27], [moonB, Math.PI, 22]].forEach(([e, ph, r]) => {
      const th = t * 1.15 + ph, x0 = Math.cos(th) * 520, y0 = Math.sin(th) * 170, c = Math.cos(-10 * Math.PI / 180), s = Math.sin(-10 * Math.PI / 180);
      T(e, { o: oo, x: 540 + x0 * c - y0 * s - r, y: 594 + x0 * s + y0 * c - r, s: 1 + Math.sin(th) * .28 });
    });
    slam(wa1, t, T0 + P, .09); slam(wb, t, T0 + P + BT, .08);
    pop(wa, t, T0 + BAR, undefined, { s0: .4, y0: 90, d: .55 });
    wa.style.transform += ` scale(${1 + Math.max(0, Math.sin((t - T0 - BAR - .45) * Math.PI * 2 / P)) * .035 * pr(t, T0 + BAR + .45, .3)})`;
    rings.forEach((r, i) => { const s0 = T0 + BAR + .45 + i * P / 2, c = (((t - s0) % P) + P) % P / P; T(r, { o: t > s0 ? (1 - c) * .7 : 0, s: 1 + c * .22 }); });
    T(sub, { o: pr(t, T0 + BAR + .6, .5), y: (1 - eo5(pr(t, T0 + BAR + .6, .6))) * 30 });
  } });
})();
