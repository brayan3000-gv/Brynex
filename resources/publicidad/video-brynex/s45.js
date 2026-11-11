// ── Escena 4 (18–24 s): la oferta. Tres niveles de ayuda ──────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="line" style="top:296px;font-size:132px"></div>`, el), l2 = h(`<div class="line" style="top:452px;font-size:80px"></div>`, el);
  const w1 = words(l1, 'Tú eliges'), w2 = words(l2, 'cuánto te ayudamos', { all: 'grad' });
  const O = [['Solo el programa', 'Tu equipo trabaja en BryNex.'], ['Razones sociales', 'Afilias bajo nuestras empresas.'], ['Todo lo operativo', 'Hacemos los trámites por ti.']].map(([t, d], i) => {
    const c = h(`<div class="opt" style="top:${600 + i * 292}px;height:266px"><div class="glow"></div><div class="n" style="top:38px">${i + 1}</div><div class="t" style="top:58px;font-size:54px">${t}</div><div class="d" style="top:148px;font-size:34px">${d}</div></div>`, el);
    return { c, g: c.querySelector('.glow'), n: c.querySelector('.n'), at: 19 + i };
  });
  const foot = h(`<div class="abs" style="left:0;width:1080px;top:1486px;text-align:center;font-size:62px;white-space:nowrap"></div>`, el);
  const fw = words(foot, 'Según tus afiliados.', { 1: 'grad', 2: 'grad' });
  scenes.push({ a: 18.0, b: 24.02, el, draw(t) {
    slam(w1, t, 18.12, .12); slam(w2, t, 18.42, .09);
    O.forEach((o, i) => {
      const k = pr(t, o.at, .55), e = eo5(k), out = ei3(pr(t, 23.55 + i * .06, .32));
      T(o.c, { o: pr(t, o.at, .1) * (1 - out), x: (1 - e) * 760, ry: (1 - e) * -40, r: (1 - e) * 6, s: lerp(.8, 1, back(k)) + (t >= 22 ? Math.sin((t - 22) * 6 - i * .7) * .012 : 0), y: -out * 220, blur: (1 - e) * 8 + out * 10 });
      const active = t < o.at + 1 ? pr(t, o.at, .2) : 1 - pr(t, o.at + 1, .35);
      o.g.style.opacity = Math.max(active, pr(t, 22, .3) * (.55 + .45 * Math.sin((t - 22) * 6 - i * .7)));
      o.n.style.transform = `scale(${lerp(.3, 1, back(pr(t, o.at + .12, .45)))}) rotate(${(1 - eo5(pr(t, o.at + .12, .5))) * -30}deg)`;
    });
    slam(fw, t, 22.2, .1);
    const out = ei3(pr(t, 23.6, .35));
    [l1, l2, foot].forEach(x => { x.style.opacity = 1 - out; x.style.transform = `translateY(${-out * 120}px)`; });
  } });
})();

// ── Escena 5 (24–30 s): el cierre ─────────────────────────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const dots = Array.from({ length: 34 }, (_, i) => ({ el: h(`<div class="abs" style="width:${6 + rnd(i) * 10}px;height:${6 + rnd(i) * 10}px;border-radius:50%;background:#93c5fd"></div>`, el), x: rnd(i + 70) * 1080, v: 60 + rnd(i + 20) * 150, ph: rnd(i + 5) * 1920 }));
  const halo = h(`<div class="abs" style="left:90px;top:330px;width:900px;height:700px;border-radius:50%;background:radial-gradient(closest-side,rgba(59,130,246,.55),transparent);filter:blur(30px)"></div>`, el);
  const logo = h(`<img class="abs" src="../../../public/img/logo-brynex.png" style="left:110px;top:500px;width:860px;transform-origin:50% 50%">`, el);
  const a = h(`<div class="abs" style="left:0;width:1080px;top:872px;text-align:center;font-size:150px;line-height:1;white-space:nowrap"></div>`, el);
  const b = h(`<div class="abs" style="left:0;width:1080px;top:1030px;text-align:center;font-size:150px;line-height:1;white-space:nowrap"></div>`, el);
  const wa1 = words(a, 'Somos'), wb = words(b, 'tu aliado.', { all: 'grad' });
  const rings = [0, 1].map(() => h(`<div class="ring" style="top:1296px"></div>`, el));
  const wa = h(`<div id="wa" style="top:1296px;font-size:45px"><svg width="64" height="64" viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"><path d="M32 8a24 24 0 0 0-20.6 36.3L8 56l12-3.3A24 24 0 1 0 32 8z"/><path d="M24 26c0 8 6 14 14 14" /></svg>Escríbenos por WhatsApp</div>`, el);
  const sub = h(`<div class="abs" style="left:0;width:1080px;top:1486px;text-align:center;font-size:31px;font-weight:600;color:#93c5fd;letter-spacing:.04em;white-space:nowrap">Solo programa · Razones sociales · Todo lo operativo</div>`, el);
  scenes.push({ a: 24.0, b: 30.01, el, draw(t) {
    dots.forEach(d => { const y = 1920 - ((t * d.v + d.ph) % 2100); T(d.el, { o: .1 + .3 * Math.abs(Math.sin(t * 1.3 + d.ph)), x: d.x + Math.sin(t + d.ph) * 30, y }); });
    const k = pr(t, 24.02, .7);
    T(logo, { o: pr(t, 24.02, .12), s: lerp(2, 1, eo5(k)) + Math.sin(t * 1.4) * .008, blur: (1 - k) * 18 });
    T(halo, { o: pr(t, 24.1, .6) * (.75 + .25 * Math.sin(t * 2.2)), s: 1 + Math.sin(t * 1.1) * .05 });
    slam(wa1, t, 24.8, .1); slam(wb, t, 25.05, .14);
    pop(wa, t, 26.0, undefined, { s0: .4, y0: 90, d: .55 });
    wa.style.transform += ` scale(${1 + Math.max(0, Math.sin((t - 26.5) * Math.PI * 2 / 1.0)) * .035 * pr(t, 26.5, .3)})`;
    rings.forEach((r, i) => { const c = ((t - 26.5 - i * .5) % 1.0 + 1) % 1.0; T(r, { o: t > 26.5 + i * .5 ? (1 - c) * .7 : 0, s: 1 + c * .22 }); });
    T(sub, { o: pr(t, 26.7, .5), y: (1 - eo5(pr(t, 26.7, .6))) * 30 });
  } });
})();
