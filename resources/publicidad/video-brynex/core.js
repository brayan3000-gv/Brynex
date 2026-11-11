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

// Fondo: dos luces azules que derivan; en la parte del dolor se tiñen de rojo.
const bA = document.getElementById('blobA'), bB = document.getElementById('blobB'), bC = document.getElementById('blobC');
const grid = document.getElementById('grid'), flash = document.getElementById('flash');
const mix = (c1, c2, k) => `rgb(${c1.map((v, i) => Math.round(lerp(v, c2[i], k))).join(',')})`;
function bg(t) {
  const pain = eio(pr(t, 2.0, 1.4)) * (1 - pr(t, 7.9, .15));
  const lift = eo3(pr(t, 8, .6));
  const a = t * .32;
  bA.style.cssText = `width:900px;height:900px;left:${-240 + Math.sin(a) * 160}px;top:${120 + Math.cos(a * .8) * 220}px;background:${mix([37, 99, 235], [185, 28, 28], pain)};opacity:${.5 + lift * .12}`;
  bB.style.cssText = `width:820px;height:820px;left:${520 + Math.cos(a * 1.2) * 150}px;top:${1040 + Math.sin(a * .9) * 240}px;background:${mix([30, 64, 175], [127, 29, 29], pain)};opacity:.55`;
  bC.style.cssText = `width:520px;height:520px;left:${300 + Math.sin(a * 1.7 + 2) * 260}px;top:${720 + Math.cos(a * 1.3) * 300}px;background:${mix([56, 189, 248], [245, 158, 11], pain)};opacity:${.14 + lift * .1}`;
  grid.style.transform = `translate(${(t * 14) % 120}px,${(t * 22) % 120}px)`;
  grid.style.opacity = .8 - pain * .4;
  // Golpes de luz en los cortes fuertes.
  const f = Math.max(1 - pr(t, 8, .5), 0) * (t >= 8 ? 1 : 0) * .95 + (t >= 24 ? (1 - pr(t, 24, .45)) * .7 : 0) + (t >= 18 ? (1 - pr(t, 18, .3)) * .3 : 0);
  flash.style.opacity = f;
}
window.render = function (t) {
  bg(t);
  // Cámara: respira siempre y tiembla cuando el caos crece.
  const shake = pr(t, 4.4, 3) * (t < 8 ? 1 : 0) * 9;
  const sx = Math.sin(t * 61) * shake, sy = Math.cos(t * 47) * shake;
  cam.style.transform = `translate(${sx}px,${sy}px) scale(${1 + Math.sin(t * .7) * .006})`;
  for (const s of scenes) {
    const on = t >= s.a && t < s.b;
    s.el.style.display = on ? 'block' : 'none';
    if (on) s.draw(t);
  }
};
