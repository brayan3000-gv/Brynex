// ── Escena 2 (3,75–9,38 s): EPS sin pensión y el precio ────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="ctr" style="top:290px;font-size:72px"></div>`, el), l2 = h(`<div class="ctr" style="top:380px;font-size:118px"></div>`, el);
  const w1 = words(l1, 'Ahora tienes'), w2 = words(l2, 'EPS sin pensión', { all: 'grad' });
  const halo = h(`<div class="abs" style="left:90px;top:520px;width:900px;height:520px;border-radius:50%;background:radial-gradient(closest-side,rgba(59,130,246,.6),transparent);filter:blur(30px)"></div>`, el);
  const price = h(`<div class="price tn" style="top:620px"></div>`, el);
  const mes = h(`<div class="ctr" style="top:872px;font-size:70px;color:#bfdbfe">al mes</div>`, el);
  const P = ['Todo incluido', 'También el primer mes', 'Sin pago de afiliación'].map((txt, i) => h(`<div class="pill" style="top:${1030 + i * 128}px"><div class="z">${check}</div>${txt}</div>`, el));
  const fmt = n => '$' + Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  scenes.push({ a: 3.75, b: 9.39, el, draw(t) {
    slam(w1, t, 3.8, .1); slam(w2, t, 4.0, .12);
    // Cuenta como una máquina tragamonedas y frena en seco en el golpe.
    const c = pr(t, 4.6875, .9375), land = pr(t, 5.625, .5);
    const n = t < 5.625 ? Math.floor(eo3(c) * 125) * 1000 + (c < 1 ? Math.floor(rnd(Math.floor(t * 30)) * 999) : 0) : 125000;
    price.textContent = fmt(n);
    price.className = 'price tn' + (t >= 5.625 ? ' grad' : '');
    T(price, { o: pr(t, 4.6875, .1), s: t < 5.625 ? .9 : lerp(1.35, 1, back(land)), blur: t < 5.625 ? 6 : (1 - eo5(land)) * 10 });
    T(halo, { o: pr(t, 5.625, .3) * (.7 + .3 * Math.sin(t * 3)), s: 1 + (t >= 5.625 ? Math.exp(-(t - 5.625) * 5) * .4 : 0) });
    pop(mes, t, 5.72, undefined, { s0: .5, y0: 30, d: .4 });
    P.forEach((p, i) => { const at = 6.5625 + i * BT, q = pr(t, at, .5); T(p, { o: pr(t, at, .1), x: (1 - eo5(q)) * (i % 2 ? -700 : 700), s: lerp(.8, 1, back(q)), blur: (1 - eo5(q)) * 8 }); });
    const out = ei3(pr(t, 9.15, .22));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 150}px)`;
  } });
})();
