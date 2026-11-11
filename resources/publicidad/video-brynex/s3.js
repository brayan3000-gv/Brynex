// ── Escena 3 (8–18 s): el giro. BryNex lo hace por ti ─────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const LOGO = '../../../public/img/logo-brynex.png';
  const rings = [0, 1, 2].map(() => h(`<div class="abs" style="left:240px;top:476px;width:600px;height:600px;border-radius:50%;border:6px solid #93c5fd"></div>`, el));
  const logo = h(`<img class="abs" src="${LOGO}" style="left:160px;top:640px;width:760px;transform-origin:50% 50%">`, el);
  const tag = h(`<div class="abs" style="left:0;width:1080px;top:960px;text-align:center;font-size:124px;white-space:nowrap"></div>`, el);
  const tagW = words(tag, 'lo hace por ti.', { 2: 'grad', 3: 'grad' });

  // Marco común de cada función: antetítulo, titular de dos líneas y tarjeta.
  const feat = (kick, l1, l2, cardH) => {
    const f = h(`<div class="full" style="transform-style:preserve-3d;transform-origin:540px 1000px"></div>`, el);
    h(`<div class="kick" style="top:352px">${kick}</div>`, f);
    const a = h(`<div class="head" style="top:404px"></div>`, f), b = h(`<div class="head" style="top:530px"></div>`, f);
    const card = h(`<div class="glass" style="left:80px;top:724px;width:920px;height:${cardH}px"></div>`, f);
    return { f, card, w1: words(a, l1), w2: words(b, l2, { all: 'grad' }) };
  };
  const frame = (F, t, a, b) => {
    const i = eo5(pr(t, a, .55)), o = ei3(pr(t, b, .32));
    T(F.f, { o: pr(t, a, .12) * (1 - o), x: (1 - i) * 900 - o * 900, ry: (1 - i) * -28 + o * 28, s: 1 - o * .1, blur: (1 - i) * 8 + o * 10 });
    slam(F.w1, t, a + .08, .1); slam(F.w2, t, a + .26, .1);
  };

  // 1 · Afiliaciones automáticas
  const F1 = feat('AFILIACIONES', 'Afiliaciones', 'automáticas', 824);
  h(`<div class="abs" style="left:44px;top:38px;font-size:42px">Nuevo afiliado</div>`, F1.card);
  const ced = h(`<div class="abs" style="left:44px;top:112px;width:832px;height:124px;border-radius:26px;background:rgba(5,11,23,.6);border:3px solid #3b82f6;padding:16px 28px"><div style="font-size:24px;font-weight:600;color:#93c5fd">Cédula</div><div id="cedv" style="font-size:50px;font-variant-numeric:tabular-nums;letter-spacing:.04em"></div></div>`, F1.card);
  const cedv = ced.querySelector('#cedv');
  const R = [['EPS', 'Salud', '#0ea5e9'], ['ARL', 'Riesgos laborales', '#f59e0b'], ['CCF', 'Caja de compensación', '#10b981'], ['AFP', 'Pensión', '#8b5cf6']].map(([s, n, c], i) => {
    const r = h(`<div class="row" style="top:${270 + i * 132}px"><div class="ico" style="background:${c}">${s}</div><div>${n}</div><div class="st"><div class="a" style="color:#93c5fd">Enviando<div class="spin"></div></div><div class="b" style="color:#86efac">Radicada<div class="ok">${check}</div></div></div></div>`, F1.card);
    return { r, a: r.querySelector('.a'), b: r.querySelector('.b'), sp: r.querySelector('.spin'), ok: r.querySelector('.ok'), in: 10.25 + i * .09, done: 10.9 + i * .35 };
  });

  // 2 · Planillas liquidadas
  const F2 = feat('PLANILLAS', 'Planillas', 'liquidadas', 800);
  h(`<div class="abs" style="left:44px;top:40px;font-size:42px">Planilla PILA</div><div class="abs" style="left:auto;right:44px;top:38px;height:60px;padding:0 26px;border-radius:30px;background:rgba(147,197,253,.16);color:#bfdbfe;font-size:28px;font-weight:600;display:flex;align-items:center">Noviembre 2026</div>`, F2.card);
  F2.card.lastChild && 0;
  const chip = h(`<div class="abs" style="left:auto;right:44px;top:38px;height:60px;padding:0 26px;border-radius:30px;background:rgba(147,197,253,.16);color:#bfdbfe;font-size:28px;font-weight:600;display:flex;align-items:center">Noviembre 2026</div>`, F2.card);
  const PR = [0, 1, 2].map(i => h(`<div class="row" style="top:${134 + i * 128}px"><div class="ico" style="border-radius:50%;background:rgba(147,197,253,.3)"></div><div style="flex:1"><div class="sk" style="width:${[330, 270, 360][i]}px;margin-bottom:14px"></div><div class="sk" style="width:${[190, 220, 160][i]}px;opacity:.55"></div></div><div class="sk" style="width:150px;height:30px;border-radius:15px;background:rgba(147,197,253,.4)"></div></div>`, F2.card));
  const bar = h(`<div class="abs" style="left:44px;top:530px;width:832px;height:26px;border-radius:13px;background:rgba(5,11,23,.6);overflow:hidden"><div style="height:100%;width:100%;border-radius:13px;background:linear-gradient(90deg,#3b82f6,#93c5fd);transform-origin:0 50%"></div></div>`, F2.card);
  const barL = h(`<div class="abs" style="left:44px;top:570px;font-size:28px;font-weight:600;color:#93c5fd"></div>`, F2.card);
  const stamp = h(`<div class="abs" style="left:232px;top:230px;width:456px;height:136px;border-radius:26px;border:9px solid #22c55e;color:#4ade80;font-size:70px;letter-spacing:.06em;display:flex;align-items:center;justify-content:center;background:rgba(5,11,23,.82);box-shadow:0 0 80px rgba(34,197,94,.55)">LIQUIDADA</div>`, F2.card);
  const pay = h(`<div class="abs" style="left:44px;top:640px;width:832px;height:116px;border-radius:58px;background:linear-gradient(120deg,#3b82f6,#1d4ed8);display:flex;align-items:center;justify-content:center;gap:20px;font-size:42px;box-shadow:0 20px 70px rgba(37,99,235,.65)"><svg width="46" height="46" viewBox="0 0 46 46" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round"><path d="M19 27a9 9 0 0 0 13 0l7-7a9 9 0 0 0-13-13l-3 3"/><path d="M27 19a9 9 0 0 0-13 0l-7 7a9 9 0 0 0 13 13l3-3"/></svg>Enlace de pago listo</div>`, F2.card);

  // 3 · WhatsApp con IA
  const F3 = feat('ATENCIÓN', 'WhatsApp', 'con IA', 800);
  h(`<div class="abs" style="left:44px;top:34px;width:92px;height:92px;border-radius:50%;background:linear-gradient(140deg,#25d366,#128c4b);display:flex;align-items:center;justify-content:center;font-size:36px">IA</div><div class="abs" style="left:160px;top:34px;font-size:40px">Asistente BryNex</div>`, F3.card);
  h(`<div class="abs" style="left:160px;top:34px;font-size:40px">Asistente BryNex</div>`, F3.card);
  h(`<div class="abs" style="left:160px;top:88px;font-size:28px;font-weight:500;color:#86efac">en línea · responde solo</div>`, F3.card);
  h(`<div class="abs" style="left:0;top:156px;width:920px;height:2px;background:rgba(147,197,253,.2)"></div>`, F3.card);
  const b1 = h(`<div class="bub in" style="top:190px">Hola, ¿cuánto vale afiliarme como independiente?</div>`, F3.card);
  const typ = h(`<div class="bub out" style="top:384px;display:flex;gap:14px;padding:34px 36px"><i></i><i></i><i></i></div>`, F3.card);
  [...typ.children].forEach(d => d.style.cssText = 'width:22px;height:22px;border-radius:50%;background:#fff;display:block');
  const b2 = h(`<div class="bub out" style="top:384px">¡Hola! Con gusto. Te cotizo ahora mismo.</div>`, F3.card);
  const b3 = h(`<div class="bub out" style="top:568px;width:560px;background:#0f172a;border:3px solid #3b82f6"><div style="font-weight:700;margin-bottom:16px">Tu cotización</div><div class="sk" style="width:420px;margin-bottom:14px"></div><div class="sk" style="width:300px;opacity:.6"></div></div>`, F3.card);

  scenes.push({ a: 8.0, b: 18.02, el, draw(t) {
    // Golpe: aros, logo y frase. Luego el logo sube y se queda como firma.
    rings.forEach((r, i) => { const k = pr(t, 8 + i * .16, 1.1); T(r, { o: (1 - k) * .8 * (k > 0), s: lerp(.2, 3.4, eo3(k)) }); });
    const up = eio(pr(t, 9.15, .5)), k = pr(t, 8.02, .55), fin = pr(t, 17.7, .25);
    T(logo, { o: pr(t, 8.02, .1) * (1 - fin), s: lerp(lerp(2.2, 1, eo5(k)), .42, up), y: -up * 536, blur: (1 - k) * 16 });
    slam(tagW, t, 8.45, .1);
    const tOut = ei3(pr(t, 9.12, .3)); tag.style.opacity = 1 - tOut; tag.style.transform = `translateY(${-tOut * 90}px) scale(${1 - tOut * .2})`; tag.style.visibility = tOut >= 1 ? 'hidden' : 'visible';

    frame(F1, t, 9.45, 12.25);
    const n = Math.floor(pr(t, 9.75, .6) * 13);
    cedv.textContent = '1.107.•••.•••'.slice(0, n) + (n < 13 && (t * 5 % 1) < .5 ? '▏' : '');
    R.forEach(o => {
      const d = pr(t, o.done, .4);
      T(o.r, { o: pr(t, o.in, .15), y: (1 - eo5(pr(t, o.in, .4))) * 50 });
      o.r.style.borderColor = d > 0 ? `rgba(34,197,94,${.25 + .5 * (1 - pr(t, o.done, 1))})` : 'rgba(147,197,253,.16)';
      o.a.style.opacity = d > 0 ? 0 : 1; o.b.style.opacity = d > 0 ? 1 : 0;
      o.sp.style.transform = `rotate(${t * 720}deg)`; o.ok.style.transform = `scale(${back(d)})`;
    });

    frame(F2, t, 12.4, 15.12);
    PR.forEach((r, i) => T(r, { o: pr(t, 12.75 + i * .1, .15), y: (1 - eo5(pr(t, 12.75 + i * .1, .4))) * 50 }));
    const bk = eio(pr(t, 12.95, .85));
    bar.firstChild.style.transform = `scaleX(${bk})`; barL.textContent = bk < 1 ? `Liquidando… ${Math.round(bk * 100)} %` : 'Lista para pagar';
    barL.style.color = bk < 1 ? '#93c5fd' : '#86efac';
    const sk = pr(t, 13.85, .3); T(stamp, { o: pr(t, 13.85, .06), s: lerp(2.6, 1, eo5(sk)), r: -8, blur: (1 - sk) * 10 });
    const pk = pr(t, 14.2, .45); T(pay, { o: pr(t, 14.2, .12), y: (1 - back(pk)) * 90, s: 1 + Math.sin((t - 14.2) * 9) * .018 * pk });

    frame(F3, t, 15.25, 17.72);
    pop(b1, t, 15.6, undefined, { s0: .5, y0: 40 });
    const ty = t >= 16.05 && t < 16.62; typ.style.visibility = ty ? 'visible' : 'hidden'; typ.style.opacity = ty ? 1 : 0;
    [...typ.children].forEach((d, i) => d.style.transform = `translateY(${-Math.max(0, Math.sin((t - 16.05) * 11 - i * .9)) * 12}px)`);
    pop(b2, t, 16.62, undefined, { s0: .5, y0: 40 });
    pop(b3, t, 17.0, undefined, { s0: .5, y0: 40 });
  } });
})();
