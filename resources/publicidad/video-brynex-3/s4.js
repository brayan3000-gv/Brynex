// ── Escena 4 (15–18,75 s): te afiliamos hoy ────────────────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const l1 = h(`<div class="ctr" style="top:280px;font-size:120px"></div>`, el), l2 = h(`<div class="ctr" style="top:420px;font-size:96px"></div>`, el);
  const w1 = words(l1, 'Pagas hoy,'), w2 = words(l2, 'te afiliamos hoy.', { all: 'grad' });
  const track = h(`<div id="track" style="top:700px;height:560px"><div id="fill" style="top:0;bottom:auto"></div></div>`, el), fill = track.querySelector('#fill');
  const Y = [760, 1180], AT = [15.9375, 16.875];
  const L = [['Hoy mismo', 'Te enviamos tu radicado y ya<br>te atienden por urgencias.'], ['En 2 a 3 días', 'Ya estás en el sistema<br>de tu EPS.']].map(([a, b], i) => {
    const node = h(`<div class="node" style="top:${Y[i] - 64}px"><u></u><span>${i + 1}</span></div>`, el);
    return { node, u: node.querySelector('u'), lv: h(`<div class="lv" style="top:${Y[i] - 62}px"><b>${a}</b><p>${b}</p></div>`, el) };
  });
  const l3 = h(`<div class="ctr" style="top:1430px;font-size:66px"></div>`, el);
  const w3 = words(l3, 'Sin filas. Sin vueltas.', { 3: 'grad' });
  scenes.push({ a: 15, b: 18.76, el, draw(t) {
    slam(w1, t, 15.05, .14); slam(w2, t, 15.35, .1);
    const tr = eo5(pr(t, 15.45, .4));
    track.style.opacity = tr; track.style.transform = `scaleY(${tr})`; track.style.transformOrigin = '50% 0%';
    fill.style.height = lerp(0, 60, eio(pr(t, 15.55, .4))) + lerp(0, 500, eio(pr(t, 16.45, .45))) + 'px';
    L.forEach((o, i) => {
      const on = t >= AT[i], d = t - AT[i];
      T(o.node, { o: pr(t, 15.5 + i * .12, .25) * (on ? 1 : .55), s: on ? 1 + .4 * Math.exp(-d * 7) : .82 });
      o.u.style.opacity = pr(t, AT[i], .1);
      const q = pr(t, AT[i], .5);
      T(o.lv, { o: pr(t, AT[i], .12), x: (1 - eo5(q)) * 110, blur: (1 - eo5(q)) * 8 });
    });
    slam(w3, t, 17.8125, .12);
    const out = ei3(pr(t, 18.52, .22));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 150}px)`;
  } });
})();
