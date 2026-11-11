// Motor: todo se calcula a partir de t (segundos). Nada de animaciones CSS, para que cada
// cuadro salga idéntico sin importar cuánto tarde en dibujarse.
const DUR = 30;
const clamp = (x, a = 0, b = 1) => Math.min(b, Math.max(a, x));
const pr = (t, a, d) => clamp((t - a) / d);
const lerp = (a, b, k) => a + (b - a) * k;
const eo3 = x => 1 - Math.pow(1 - x, 3);
const eo5 = x => 1 - Math.pow(1 - x, 5);
const ei3 = x => x * x * x;
const eio = x => x < .5 ? 4 * x * x * x : 1 - Math.pow(-2 * x + 2, 3) / 2;
const back = x => { const c = 1.9; return 1 + (c + 1) * Math.pow(x - 1, 3) + c * Math.pow(x - 1, 2); };
const rnd = i => { const s = Math.sin(i * 127.1 + 311.7) * 43758.5453; return s - Math.floor(s); };
const cam = document.getElementById('cam');
const scenes = [];

function h(html, parent) {
  const d = document.createElement('div'); d.innerHTML = html.trim();
  const el = d.firstChild; (parent || cam).appendChild(el); return el;
}
function T(el, o) {
  const op = o.o === undefined ? 1 : o.o;
  // Solo opacidad: con visibility un hijo visible se asoma aunque su tarjeta esté oculta.
  el.style.opacity = op; if (op <= 0.002) return;
  el.style.transform = `translate3d(${o.x || 0}px,${o.y || 0}px,${o.z || 0}px) rotateX(${o.rx || 0}deg) rotateY(${o.ry || 0}deg) rotate(${o.r || 0}deg) scale(${o.s === undefined ? 1 : o.s})`;
  el.style.filter = o.blur > 0.3 ? `blur(${o.blur}px)` : 'none';
}
// Entrada con rebote: aparece en `a`, sale en `b` (opcional).
function pop(el, t, a, b, o = {}) {
  const i = pr(t, a, o.d || .45), k = back(i);
  const out = b === undefined ? 0 : ei3(pr(t, b, o.dout || .3));
  T(el, { o: pr(t, a, .12) * (1 - out), s: lerp(o.s0 === undefined ? .6 : o.s0, 1, k) * (1 - out * .15),
    y: lerp(o.y0 === undefined ? 60 : o.y0, 0, eo5(i)) - out * (o.yout === undefined ? 80 : o.yout), x: lerp(o.x0 || 0, 0, eo5(i)), blur: (1 - i) * 10 + out * 14 });
}
// Palabras que caen una por una.
function words(line, txt, cls = {}) {
  line.innerHTML = '';
  return txt.split(' ').map((w, i, a) => {
    const s = document.createElement('span'); s.className = 'w ' + (cls[i] || cls.all || '');
    s.textContent = w + (i < a.length - 1 ? ' ' : ''); line.appendChild(s); return s;
  });
}
function slam(ws, t, a, step = .11) {
  ws.forEach((w, i) => {
    const k = pr(t, a + i * step, .34), e = eo5(k);
    w.style.opacity = pr(t, a + i * step, .08);
    w.style.transform = `translateY(${lerp(70, 0, e)}px) scale(${lerp(1.5, 1, e)})`;
    w.style.filter = k < 1 ? `blur(${(1 - e) * 14}px)` : 'none';
  });
}
const check = `<svg width="34" height="34" viewBox="0 0 34 34"><path d="M7 18l7 7L27 10" fill="none" stroke="#fff" stroke-width="5.5" stroke-linecap="round" stroke-linejoin="round"/></svg>`;

// Compás: 128 BPM → un tiempo = 0,46875 s y un compás = 1,875 s. 16 compases = 30 s justos.
const BT = 0.46875, BAR = 1.875;
const mix = (c1, c2, k) => c1.map((v, i) => lerp(v, c2[i], k));
const rgb = c => `rgb(${c.map(Math.round).join(',')})`;
const sky = document.getElementById('sky'), moon = document.getElementById('moon'), sun = document.getElementById('sun');
const bA = document.getElementById('blobA'), bB = document.getElementById('blobB'), bC = document.getElementById('blobC');
const grid = document.getElementById('grid'), flash = document.getElementById('flash'), starsEl = document.getElementById('stars');
const stars = Array.from({ length: 80 }, (_, i) => {
  const el = document.createElement('div'); el.className = 'star'; const s = 3 + rnd(i) * 5;
  el.style.cssText = `width:${s}px;height:${s}px`; starsEl.appendChild(el);
  return { el, x: rnd(i + 40) * 1200, y: Math.pow(rnd(i + 90), 1.4) * 1700, ph: rnd(i + 7) * 9, v: 4 + rnd(i + 3) * 8 };
});
const BUZZ = [1.40625, 1.875, 2.34375];

// Cielo: noche (0–12 s) → amanecer (13–18 s) → azul de marca (19 s en adelante).
function bg(t) {
  const d = eio(pr(t, 11.4, 2.2)), brand = eio(pr(t, 18.3, .8));
  const N = [[3, 6, 20], [9, 13, 42], [22, 22, 70]], D = [[26, 38, 92], [92, 70, 132], [251, 138, 72]], B = [[5, 11, 23], [5, 11, 23], [5, 11, 23]];
  const c = [0, 1, 2].map(i => mix(mix(N[i], D[i], d), B[i], brand));
  sky.style.background = `linear-gradient(180deg,${rgb(c[0])} 0%,${rgb(c[1])} 58%,${rgb(c[2])} 100%)`;
  const night = 1 - d;
  starsEl.style.opacity = night;
  if (night > 0.01) stars.forEach(s => {
    s.el.style.transform = `translate(${((s.x - t * s.v) % 1200 + 1200) % 1200 - 60}px,${s.y}px)`;
    s.el.style.opacity = .25 + .75 * Math.abs(Math.sin(t * 1.1 + s.ph));
  });
  const m = t / 13;
  moon.style.opacity = night; moon.style.transform = `translate(${lerp(880, 610, m)}px,${96 + m * m * 70}px)`;
  const up = eo3(pr(t, 12.3, 3.6));
  sun.style.opacity = d * (1 - brand); sun.style.transform = `translate(${540 - 800}px,${lerp(2500, 1790, up) - 800}px) scale(${1 + Math.sin(t * 1.3) * .02})`;
  // Las luces azules de la marca vuelven al final.
  const a = t * .32;
  bA.style.cssText = `width:900px;height:900px;left:${-240 + Math.sin(a) * 160}px;top:${120 + Math.cos(a * .8) * 220}px;background:rgb(37,99,235);opacity:${brand * .55}`;
  bB.style.cssText = `width:820px;height:820px;left:${520 + Math.cos(a * 1.2) * 150}px;top:${1040 + Math.sin(a * .9) * 240}px;background:rgb(30,64,175);opacity:${brand * .55}`;
  bC.style.cssText = `width:560px;height:560px;left:${260 + Math.sin(a * 1.7 + 2) * 260}px;top:${560 + Math.cos(a * 1.3) * 300}px;background:rgb(99,102,241);opacity:${.2 * night + .2 * brand}`;
  grid.style.transform = `translate(${(t * 14) % 120}px,${(t * 22) % 120}px)`; grid.style.opacity = brand * .8;
  // Golpes de luz en los cortes.
  const hit = (a, d, g) => t >= a ? (1 - pr(t, a, d)) * g : 0;
  const warm = hit(13.125, .6, .75);
  flash.style.background = warm > 0 ? '#ffedd5' : '#dbeafe';
  flash.style.opacity = hit(3.75, .4, .55) + warm + hit(18.75, .3, .3) + hit(24.375, .45, .7);
}
window.render = function (t) {
  bg(t);
  // El teléfono vibra: la cámara tiembla un instante con cada mensaje.
  let sh = 0; BUZZ.forEach(b => { if (t >= b && t < b + .3) sh = Math.max(sh, (1 - (t - b) / .3) * 11); });
  cam.style.transform = `translate(${Math.sin(t * 190) * sh}px,${Math.cos(t * 163) * sh * .5}px) scale(${1 + Math.sin(t * .7) * .006})`;
  for (const s of scenes) {
    const on = t >= s.a && t < s.b;
    s.el.style.display = on ? 'block' : 'none';
    if (on) s.draw(t);
  }
};
