// ── Escena 1 (0–2 s): el gancho ───────────────────────────────────────────────
(() => {
  const el = h(`<div class="scene"></div>`);
  const mk = (top, size, color) => h(`<div class="line" style="top:${top}px;font-size:${size}px;color:${color || '#fff'}"></div>`, el);
  const L = [
    [mk(430, 150), '¿Todavía', {}, .08], [mk(592, 150), 'afilias', {}, .30],
    [mk(760, 204), 'a mano,', { all: 'grad' }, .54],
    [mk(1050, 122, '#dbeafe'), 'portal por', {}, 1.00], [mk(1186, 122, '#dbeafe'), 'portal?', {}, 1.30],
  ].map(([line, txt, cls, at]) => ({ ws: words(line, txt, cls), at }));
  const ul = h(`<div class="abs" style="left:84px;top:966px;height:16px;width:700px;border-radius:8px;background:linear-gradient(90deg,#93c5fd,#2563eb);transform-origin:0 50%"></div>`, el);
  scenes.push({ a: 0, b: 2.12, el, draw(t) {
    L.forEach(l => slam(l.ws, t, l.at, .13));
    ul.style.transform = `scaleX(${eo5(pr(t, .72, .4))})`; ul.style.opacity = pr(t, .72, .1);
    const out = ei3(pr(t, 1.82, .3));
    el.style.transform = `scale(${1 + out * .9})`; el.style.opacity = 1 - out; el.style.filter = out > .02 ? `blur(${out * 22}px)` : 'none';
  } });
})();

// ── Escena 2 (2–8 s): el dolor. Ventanas que se amontonan y el día que se va ──
(() => {
  const el = h(`<div class="scene"></div>`);
  const chaos = h(`<div class="full" style="transform-origin:540px 820px;transform-style:preserve-3d"></div>`, el);
  const P = [['EPS', '#0ea5e9'], ['ARL', '#f59e0b'], ['Caja', '#10b981'], ['Pensión', '#8b5cf6']];
  const win = (i) => {
    const [n, c] = P[i % 4];
    return h(`<div class="win"><div class="bar"><div class="dot" style="background:#f87171"></div><div class="dot" style="background:#fbbf24"></div><div class="dot" style="background:#34d399"></div><div class="tab"><div class="chip" style="background:${c}"></div>Portal ${n}</div></div>
      <div class="body"><div class="ttl">Ingreso al portal ${n}</div><div class="lbl">Usuario</div><div class="inp"><span class="u"></span></div><div class="lbl">Contraseña</div><div class="inp">••••••••••</div><div class="btn" style="background:${c}">Ingresar</div></div></div>`, chaos);
  };
  const W = [];
  [2.0, 2.5, 3.0, 3.5].forEach((at, i) => W.push({ el: win(i), at, x: 50 + i * 78, y: 400 + i * 158, r: (i % 2 ? 3.5 : -3.5), main: true }));
  [4.5, 4.9, 5.25, 5.55, 5.8, 6.0, 6.2, 6.38, 6.54, 6.68, 6.8, 6.92, 7.02, 7.12].forEach((at, j) =>
    W.push({ el: win(j + 1), at, x: -90 + rnd(j + 3) * 500, y: 300 + rnd(j + 40) * 760, r: (rnd(j + 9) - .5) * 22 }));
  const toasts = [[4.5, 'Sesión expirada', 420, 590, 4], [5.0, 'Captcha incorrecto', 70, 930, -5], [5.5, 'Intenta más tarde', 400, 1140, 3], [6.1, 'Usuario bloqueado', 110, 470, -3]]
    .map(([at, txt, x, y, r]) => ({ at, x, y, r, el: h(`<div class="toast" style="z-index:50"><i>!</i>${txt}</div>`, chaos) }));
  const scrim = h(`<div id="scrim" class="full"></div>`, el);
  const clock = h(`<div id="clock"><svg width="62" height="62" viewBox="0 0 62 62"><circle cx="31" cy="31" r="27" fill="none" stroke="currentColor" stroke-width="5"/><line id="hh" x1="31" y1="31" x2="31" y2="17" stroke="currentColor" stroke-width="5" stroke-linecap="round"/><line id="mh" x1="31" y1="31" x2="31" y2="10" stroke="currentColor" stroke-width="4" stroke-linecap="round"/></svg><span id="hr"></span></div>`, el);
  const hh = clock.querySelector('#hh'), mh = clock.querySelector('#mh'), hr = clock.querySelector('#hr');
  const cap = (l1, l2) => { const c = h(`<div class="cap" style="top:1290px"><div></div><div></div></div>`, el); return [c, words(c.children[0], l1), words(c.children[1], l2, { all: 'warm' })]; };
  const C = [[cap('Un afiliado.', 'Cuatro portales.'), 2.2, 4.3], [cap('Y otro. Y otro.', 'Cada mes.'), 4.45, 6.3], [cap('Se te va', 'el día.'), 6.45, 7.7]];
  scenes.push({ a: 1.85, b: 8.0, el, draw(t) {
    W.forEach((w, i) => {
      const k = pr(t, w.at, w.main ? .5 : .32), e = back(k);
      T(w.el, { o: pr(t, w.at, .1), x: w.x, y: w.y + (1 - eo5(k)) * 240, z: lerp(-900, 0, eo5(k)), rx: (1 - eo5(k)) * 38, r: w.r * e, s: lerp(.5, w.main ? 1 : .86, e) });
      w.el.style.zIndex = i;
      if (w.main) { const n = Math.floor(pr(t, w.at + .35, 1.1) * 11); w.el.querySelector('.u').textContent = 'usuario.rrhh'.slice(0, n) + (n < 11 && (t * 4 % 1) < .5 ? '|' : ''); }
    });
    toasts.forEach(o => { const k = pr(t, o.at, .35); T(o.el, { o: pr(t, o.at, .08), x: o.x + Math.sin((t - o.at) * 38) * 14 * (1 - pr(t, o.at, .5)), y: o.y, r: o.r, s: lerp(.4, 1, back(k)) }); });
    // El reloj corre de 8:00 a. m. a 6:30 p. m., cada vez más rápido.
    const dayK = Math.pow(pr(t, 2.3, 5.2), 1.7), min = 480 + Math.round(dayK * 630 / 5) * 5;
    const H = Math.floor(min / 60), M = min % 60;
    hr.textContent = `${((H + 11) % 12) + 1}:${String(M).padStart(2, '0')} ${H < 12 ? 'a. m.' : 'p. m.'}`;
    hh.setAttribute('transform', `rotate(${(min / 720) * 360} 31 31)`); mh.setAttribute('transform', `rotate(${(min / 60) * 360} 31 31)`);
    const late = pr(t, 5.2, 2);
    clock.style.color = mix([219, 234, 254], [252, 165, 165], late); clock.style.borderColor = mix([96, 134, 190], [239, 68, 68], late);
    T(clock, { o: pr(t, 2.15, .2) * (1 - pr(t, 7.6, .25)), y: (1 - eo5(pr(t, 2.15, .5))) * -90, s: 1 + late * .12 + Math.sin(t * 30) * .012 * late });
    C.forEach(([[c, w1, w2], a, b]) => {
      const out = ei3(pr(t, b, .22));
      c.style.opacity = 1 - out; c.style.transform = `translateY(${-out * 50}px)`; c.style.visibility = (t < a - .05 || out >= 1) ? 'hidden' : 'visible';
      slam(w1, t, a, .1); slam(w2, t, a + .38, .1);
    });
    // Todo se chupa hacia el centro antes del golpe.
    const imp = ei3(pr(t, 7.48, .5));
    chaos.style.transform = `scale(${1 - imp * .96}) rotate(${imp * 55}deg)`; chaos.style.filter = imp > .02 ? `blur(${imp * 14}px)` : 'none';
    scrim.style.opacity = (1 - imp) * pr(t, 2.1, .3);
    el.style.opacity = pr(t, 1.85, .2);
  } });
})();
