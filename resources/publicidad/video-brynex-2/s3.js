// ── Escena 3 (13,13–18,75 s): amanece y el trabajo ya está hecho ────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const ck = h(`<div class="ctr tn dawn" style="top:262px;font-size:184px">8:00<span style="font-size:64px;margin-left:18px">a. m.</span></div>`, el);
  const h1 = h(`<div class="ctr" style="top:478px;font-size:84px"></div>`, el), h2 = h(`<div class="ctr" style="top:578px;font-size:84px"></div>`, el);
  const w1 = words(h1, 'Llegas y ya hay'), w2 = words(h2, 'trabajo hecho.', { all: 'dawn' });
  const card = h(`<div class="lcard"><h4>LO QUE HIZO LA IA ANOCHE</h4></div>`, el);
  const dark = `<svg width="38" height="38" viewBox="0 0 34 34"><path d="M7 18l7 7L27 10" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;
  const R = ['Clientes atendidos', 'Cotizaciones enviadas', 'Comprobantes leídos'].map((txt, i) => {
    const r = h(`<div class="lrow" style="top:${104 + i * 118}px"><div class="c"><i></i><u>${dark}</u></div><span>${txt}</span></div>`, card);
    return { el: r, u: r.querySelector('u'), txt: r.querySelector('span'), at: 15.0 + i * BT };
  });
  const dd = h(`<div class="ctr" style="top:1236px;font-size:58px;text-shadow:0 6px 30px rgba(30,20,60,.6)"></div>`, el);
  const wd = words(dd, 'Y de día, también:');
  const bolt = `<svg width="40" height="40" viewBox="0 0 40 40"><path d="M23 3L8 23h10l-2 14 15-20H21z" fill="#fff"/></svg>`;
  const C = ['Afiliaciones automáticas', 'Planillas liquidadas'].map((txt, i) => h(`<div class="chip" style="top:${1324 + i * 130}px"><div class="z">${bolt}</div>${txt}</div>`, el));
  scenes.push({ a: 13.125, b: 18.77, el, draw(t) {
    const k = pr(t, 13.13, .55);
    T(ck, { o: pr(t, 13.13, .1), s: lerp(1.8, 1, eo5(k)), blur: (1 - k) * 16 });
    slam(w1, t, 13.6, .1); slam(w2, t, 14.0625, .12);
    const ci = pr(t, 14.53125, .6);
    T(card, { o: pr(t, 14.53125, .12), y: (1 - eo5(ci)) * 500, rx: (1 - eo5(ci)) * 35, s: lerp(.8, 1, back(ci)) });
    R.forEach(r => {
      const c = pr(t, r.at, .35);
      r.u.style.opacity = pr(t, r.at, .08); r.u.style.transform = `scale(${lerp(.2, 1, back(c))})`;
      r.el.style.background = `rgba(34,197,94,${t >= r.at ? (1 - pr(t, r.at, .7)) * .25 : 0})`;
      r.txt.style.opacity = lerp(.4, 1, pr(t, r.at, .15));
    });
    slam(wd, t, 16.40625, .09);
    C.forEach((c, i) => { const at = 16.875 + i * BT, q = pr(t, at, .5); T(c, { o: pr(t, at, .1), x: (1 - eo5(q)) * 700, s: lerp(.8, 1, back(q)), blur: (1 - eo5(q)) * 8 }); });
    const out = ei3(pr(t, 18.5, .25));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 140}px) scale(${1 - out * .06})`;
  } });
})();
