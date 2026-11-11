// ── Escena 2 (5,63–13,13 s): la noche pasa y las notificaciones se apilan ───────
(() => {
  const el = h(`<div class="scene" style="transform-style:flat"></div>`);
  const l1 = h(`<div class="ctr" style="top:262px;font-size:90px"></div>`, el), l2 = h(`<div class="ctr" style="top:366px;font-size:90px"></div>`, el);
  const w1 = words(l1, 'Mientras duermes,'), w2 = words(l2, 'BryNex atiende.', { 0: 'grad' });
  const halo = h(`<div class="abs" style="left:90px;top:560px;width:900px;height:1000px;border-radius:50%;background:radial-gradient(closest-side,rgba(99,102,241,.5),transparent);filter:blur(40px)"></div>`, el);
  const ph = h(`<div class="phone" style="transform-style:flat"><div class="wall"></div><div class="notch"></div><div class="lk tn"></div></div>`, el);
  const lk = ph.querySelector('.lk');
  const svg = p => `<svg width="46" height="46" viewBox="0 0 44 44" fill="none" stroke="#fff" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round">${p}</svg>`;
  const NT = [
    ['#22c55e', '<path d="M7 9h30v21H19l-8 7v-7H7z"/>', 'Asistente IA', 'Respondió a un cliente'],
    ['#3b82f6', '<path d="M11 5h15l8 8v26H11z"/><path d="M26 5v8h8M17 23h11M17 30h11"/>', 'Cotización', 'Enviada al instante'],
    ['#8b5cf6', '<rect x="17" y="4" width="10" height="21" rx="5"/><path d="M10 21a12 12 0 0 0 24 0M22 33v7"/>', 'Nota de voz', 'Escuchada y respondida'],
    ['#f59e0b', '<path d="M11 5h22v34l-5.5-4-5.5 4-5.5-4-5.5 4z"/><path d="M17 15h10M17 23h10"/>', 'Comprobante', 'Leído por la IA'],
    ['#06b6d4', '<circle cx="22" cy="14" r="8"/><path d="M7 39c2-9 8-13 15-13s13 4 15 13"/>', 'Afiliado atendido', 'Consulta respondida'],
    ['#2563eb', '<rect x="9" y="5" width="26" height="34" rx="4"/><path d="M15 23l5 5 9-11"/>', 'Planilla', 'Enviada al cliente'],
  ].map(([c, p, a, b], i) => ({ el: h(`<div class="nt"><div class="ic" style="background:${c}">${svg(p)}</div><b>${a}</b><p>${b}</p><em>ahora</em></div>`, ph), at: 6.09375 + i * .9375 }));
  scenes.push({ a: 5.625, b: 13.14, el, draw(t) {
    slam(w1, t, 5.72, .1); slam(w2, t, 6.02, .12);
    const inn = eo5(pr(t, 5.625, .8)), out = ei3(pr(t, 12.72, .42)), rec = eio(pr(t, 11.25, 1.5));
    let bump = 0, shx = 0;
    NT.forEach(n => { if (t >= n.at) { const d = t - n.at; bump += .04 * Math.exp(-d * 8); shx += Math.sin(d * 95) * 7 * Math.exp(-d * 11); } });
    T(ph, { o: pr(t, 5.625, .15) * (1 - pr(t, 12.95, .18)), y: (1 - inn) * 1300 + out * 1500 + rec * 30, x: shx,
      ry: lerp(-38, -9 + Math.sin(t * .9) * 7, inn), rx: lerp(22, 5 + Math.cos(t * .7) * 2, inn) + out * 30, r: (1 - inn) * -8,
      s: (1 + bump) * lerp(1, .93, rec) });
    T(halo, { o: inn * (1 - out) * (.7 + .3 * Math.sin(t * 2)), s: 1 + bump * 2 });
    // El reloj de la pantalla corre de las 11:50 p. m. a las 7:58 a. m.
    const mins = Math.round(lerp(23 * 60 + 50, 31 * 60 + 58, pr(t, 5.9, 7.0)));
    const hh = Math.floor(mins / 60) % 24, mm = mins % 60, h12 = ((hh + 11) % 12) + 1;
    lk.innerHTML = `${h12}:${String(mm).padStart(2, '0')}<span>${hh >= 12 ? 'p. m.' : 'a. m.'}</span>`;
    NT.forEach((n, k) => {
      let slot = 0; for (let m = k + 1; m < NT.length; m++) slot += eo5(pr(t, NT[m].at, .45));
      // Llega grande, para que se alcance a leer, y luego se acomoda en la pila.
      const d = t - n.at, big = d < .36 ? 1 : 1 - eo5(pr(d, .36, .4)), i = eo5(pr(d, 0, .3));
      T(n.el, { o: pr(d, 0, .1), y: 290 + slot * 142 - (1 - i) * 120, s: lerp(1, 1.36, big) * lerp(.6, 1, back(pr(d, 0, .35))), blur: (1 - i) * 8 });
    });
    const ho = pr(t, 12.75, .3);
    [l1, l2].forEach(x => { x.style.opacity = 1 - ho; x.style.transform = `translateY(${-ho * 80}px)`; });
  } });
})();
