// ── Escena 1 (0–5,63 s): son las 11:47 p. m., un cliente escribe… y alguien le responde ──
(() => {
  const el = h(`<div class="scene"></div>`);
  const clock = h(`<div class="ctr tn" style="top:270px;font-size:230px">11<span>:</span>47<span style="font-size:72px;margin-left:20px;color:#a5b4fc">p. m.</span></div>`, el);
  const col = clock.querySelector('span');
  const c1 = h(`<div class="ctr" style="top:548px;font-size:80px"></div>`, el);
  const w1 = words(c1, 'Tu oficina ya cerró.');
  const B = [['Hola, ¿me puedes afiliar?', 716], ['¿Cuánto me vale?', 866], ['¿Hola?', 1016]].map(([txt, top], i) => ({ el: h(`<div class="bub in" style="top:${top}px">${txt}<small>11:47 p. m.</small></div>`, el), at: BUZZ[i] }));
  const q1 = h(`<div class="ctr" style="top:1196px;font-size:138px"></div>`, el), q2 = h(`<div class="ctr" style="top:1350px;font-size:138px"></div>`, el);
  const wq1 = words(q1, '¿Quién le'), wq2 = words(q2, 'responde?', { all: 'amber' });
  // La respuesta.
  const hA = h(`<div class="ctr" style="top:404px;font-size:150px"></div>`, el), hB = h(`<div class="ctr" style="top:566px;font-size:100px"></div>`, el);
  const wA = words(hA, 'BryNex', { all: 'grad' }), wB = words(hB, 'le responde.');
  const typing = h(`<div class="bub out" style="top:1190px"><div class="dots"><i></i><i></i><i></i></div></div>`, el);
  const dots = [...typing.querySelectorAll('i')];
  const reply = h(`<div class="bub out" style="top:1190px">¡Hola! Con gusto te ayudo.<br>¿Para quién es la afiliación?</div>`, el);
  const tag = h(`<div class="tag" style="top:1392px"><svg width="30" height="30" viewBox="0 0 30 30"><path d="M15 2l3.2 9.8L28 15l-9.8 3.2L15 28l-3.2-9.8L2 15l9.8-3.2z" fill="#93c5fd"/></svg>Asistente BryNex · IA · 11:47 p. m.</div>`, el);
  scenes.push({ a: 0, b: 5.635, el, draw(t) {
    const k = pr(t, .03, .5), sh = eio(pr(t, 3.75, .45));
    T(clock, { o: pr(t, .03, .1), s: lerp(1.7, 1, eo5(k)) * lerp(1, .42, sh), y: -sh * 82, blur: (1 - k) * 16 });
    col.style.opacity = (t % (BT * 2)) < BT ? 1 : .25;
    slam(w1, t, BT, .1); c1.style.opacity = 1 - pr(t, 3.62, .13);
    B.forEach(b => pop(b.el, t, b.at, undefined, { s0: .3, y0: 40, x0: -60, d: .4 }));
    slam(wq1, t, 2.8125, .12); slam(wq2, t, 3.05, .1);
    const qo = pr(t, 3.75, .22);
    [q1, q2].forEach(x => { x.style.opacity = 1 - qo; x.style.transform = `scale(${1 + qo * .5})`; x.style.filter = qo > 0 ? `blur(${qo * 18}px)` : 'none'; });
    slam(wA, t, 3.8, .1); slam(wB, t, 3.96, .1);
    pop(typing, t, 3.84, 4.16, { s0: .4, y0: 30, d: .28, dout: .08, yout: 0 });
    dots.forEach((d, i) => { d.style.transform = `translateY(${-Math.max(0, Math.sin((t - 3.84) * 16 - i * .9)) * 14}px)`; });
    pop(reply, t, 4.21875, undefined, { s0: .5, y0: 30, d: .4 });
    pop(tag, t, 4.6875, undefined, { s0: .6, y0: 20, d: .35 });
    const out = ei3(pr(t, 5.38, .25));
    el.style.opacity = 1 - out; el.style.transform = `translateY(${-out * 160}px)`;
  } });
})();
