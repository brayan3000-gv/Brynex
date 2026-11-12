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
const bA = document.getElementById('blobA'), bB = document.getElementById('blobB'), bC = document.getElementById('blobC');
const grid = document.getElementById('grid'), flash = document.getElementById('flash'), sky = document.getElementById('sky');
const dust = Array.from({ length: 40 }, (_, i) => {
  const el = document.createElement('div'); el.className = 'dust'; const s = 5 + rnd(i) * 9;
  el.style.cssText = `width:${s}px;height:${s}px`; document.getElementById('dustL').appendChild(el);
  return { el, x: rnd(i + 70) * 1080, v: 50 + rnd(i + 20) * 120, ph: rnd(i + 5) * 1920 };
});
// Los golpes del candado (el teléfono tiembla igual que en el video 2).
const HITS = [1.875, 2.34375, 2.8125];

// Fondo: arranca oscuro y rojizo (la pensión que no alcanza) y a los 3,75 s pasa al azul de
// la marca. En la escena de lo que cubre entra un toque verde de salud.
function bg(t) {
  const tension = 1 - eio(pr(t, 3.55, .35));
  const verde = pr(t, 9.375, .6) * (1 - pr(t, 14.6, .5));
  sky.style.background = tension > .01
    ? `linear-gradient(180deg,rgb(${lerp(5, 22, tension)},${lerp(11, 6, tension)},${lerp(23, 14, tension)}) 0%,#050b17 100%)`
    : '#050b17';
  const a = t * .32;
  bA.style.cssText = `width:900px;height:900px;left:${-240 + Math.sin(a) * 160}px;top:${120 + Math.cos(a * .8) * 220}px;background:${tension > .5 ? 'rgb(153,27,27)' : 'rgb(37,99,235)'};opacity:${.55 - tension * .2}`;
  bB.style.cssText = `width:820px;height:820px;left:${520 + Math.cos(a * 1.2) * 150}px;top:${1040 + Math.sin(a * .9) * 240}px;background:${verde > .02 ? `rgba(16,185,129,${.5 + verde * .5})` : 'rgb(30,64,175)'};opacity:${.55 - tension * .35}`;
  bC.style.cssText = `width:560px;height:560px;left:${260 + Math.sin(a * 1.7 + 2) * 260}px;top:${560 + Math.cos(a * 1.3) * 300}px;background:rgb(99,102,241);opacity:${.3 * (1 - tension)}`;
  grid.style.transform = `translate(${(t * 14) % 120}px,${(t * 22) % 120}px)`; grid.style.opacity = .8 * (1 - tension);
  dust.forEach(d => { const y = 1920 - ((t * d.v + d.ph) % 2100); T(d.el, { o: (1 - tension) * (.08 + .25 * Math.abs(Math.sin(t * 1.3 + d.ph))), x: d.x + Math.sin(t + d.ph) * 30, y }); });
  const hit = (a, d, g) => t >= a ? (1 - pr(t, a, d)) * g : 0;
  flash.style.opacity = hit(3.75, .45, .75) + hit(5.625, .3, .35) + hit(9.375, .3, .25) + hit(15, .3, .25) + hit(18.75, .35, .4) + hit(24.375, .45, .65);
}
window.render = function (t) {
  bg(t);
  let sh = 0; HITS.forEach(b => { if (t >= b && t < b + .3) sh = Math.max(sh, (1 - (t - b) / .3) * 14); });
  if (t >= 5.625 && t < 5.9) sh = Math.max(sh, (1 - (t - 5.625) / .275) * 16);
  cam.style.transform = `translate(${Math.sin(t * 190) * sh}px,${Math.cos(t * 163) * sh * .5}px) scale(${1 + Math.sin(t * .7) * .006})`;
  for (const s of scenes) {
    const on = t >= s.a && t < s.b;
    s.el.style.display = on ? 'block' : 'none';
    if (on) s.draw(t);
  }
};
