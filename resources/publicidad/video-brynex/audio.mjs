// Pista sintetizada por código (120 BPM, un compás = 2 s), con los golpes en los mismos
// instantes que la animación. Uso: node audio.mjs salida.wav
import fs from 'fs';
const SR = 44100, DUR = 30, N = SR * DUR;
const L = new Float32Array(N), R = new Float32Array(N), REV = new Float32Array(N), DLY = new Float32Array(N);
let seed = 12345; const noise = () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 2147483648) - 1;
const TAU = Math.PI * 2, saw = p => 2 * (p - Math.floor(p)) - 1, midi = n => 440 * Math.pow(2, (n - 69) / 12);
function voice(t0, dur, g, pan, rev, dly, fn) {
  const i0 = Math.round(t0 * SR), n = Math.round(dur * SR), gl = g * Math.cos((pan + 1) * Math.PI / 4), gr = g * Math.sin((pan + 1) * Math.PI / 4);
  for (let i = 0; i < n; i++) { const j = i0 + i; if (j < 0 || j >= N) continue; const s = fn(i / SR, i); L[j] += s * gl; R[j] += s * gr; if (rev) REV[j] += s * g * rev; if (dly) DLY[j] += s * g * dly; }
}
const lp = c => { let y = 0; const a = 1 - Math.exp(-TAU * c / SR); return x => (y += a * (x - y)); };
const hp = c => { const f = lp(c); return x => x - f(x); };

const kick = (t, g = 1) => { let ph = 0; voice(t, .45, g, 0, 0, 0, tt => { ph += (44 + 130 * Math.exp(-tt * 30)) / SR; return Math.sin(TAU * ph) * Math.exp(-tt * 8) + (tt < .004 ? noise() * .5 : 0); }); };
const hat = (t, g = .2, pan = .2) => { const f = hp(7000); voice(t, .09, g, pan, .1, 0, tt => f(noise()) * Math.exp(-tt * 70)); };
const clap = (t, g = .5) => { const a = hp(900), b = lp(5200); voice(t, .3, g, 0, .35, 0, tt => b(a(noise())) * (Math.exp(-((tt % .012) * 300)) * (tt < .036 ? 1 : 0) + Math.exp(-(tt - .036) * 22) * (tt >= .036 ? 1 : 0))); };
const bass = (t, n, dur, g = .5) => { let p = 0; const f = lp(380), fr = midi(n); voice(t, dur, g, 0, 0, 0, tt => { p += fr / SR; const e = Math.min(1, tt * 200) * Math.min(1, (dur - tt) * 40); return (f(saw(p)) * .7 + Math.sin(TAU * p) * .9) * e; }); };
const pluck = (t, n, g = .22, pan = 0) => { let p = 0, q = 0, y = 0; const fr = midi(n); voice(t, .5, g, pan, .25, .45, tt => { p += fr / SR; q += fr * 1.006 / SR; const c = 600 + 5200 * Math.exp(-tt * 14), a = 1 - Math.exp(-TAU * c / SR); y += a * ((saw(p) + saw(q)) * .5 - y); return y * Math.exp(-tt * 7) * Math.min(1, tt * 400); }); };
const pad = (t, notes, dur, g, duck, cut = 1100) => notes.forEach((n, k) => { let p = [0, 0, 0]; const f = lp(cut), fr = midi(n); voice(t, dur + .6, g, (k % 2 ? .5 : -.5), .3, 0, tt => { let s = 0; [.994, 1, 1.007].forEach((d, i) => { p[i] += fr * d / SR; s += saw(p[i]); }); const e = Math.min(1, tt / .25) * Math.min(1, Math.max(0, (dur + .6 - tt) / .6)); const dk = duck ? 1 - .6 * Math.exp(-(((t + tt) % .5)) * 9) : 1; return f(s / 3) * e * dk; }); });
const riser = (t, dur, g = .35) => { let y = 0, p = 0; voice(t, dur, g, 0, .2, 0, tt => { const k = tt / dur, c = 300 + 9000 * k * k, a = 1 - Math.exp(-TAU * c / SR); y += a * (noise() - y); p += (200 + 900 * k * k) / SR; return (y * 1.2 + Math.sin(TAU * p) * .25) * k * k; }); };
const impact = (t, g = 1) => { let p = 0; const f = lp(3200); voice(t, 2.4, g, 0, .5, 0, tt => { p += (30 + 70 * Math.exp(-tt * 9)) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 2.6) * 1.1 + f(noise()) * Math.exp(-tt * 3.2) * .5; }); };
const tick = (t, g = .12, pan = 0) => voice(t, .03, g, pan, .15, 0, tt => Math.sin(TAU * 2300 * tt) * Math.exp(-tt * 220));
const ding = (t, n, g = .3, pan = 0) => { const fr = midi(n); voice(t, .9, g, pan, .5, .3, tt => (Math.sin(TAU * fr * tt) + .4 * Math.sin(TAU * fr * 2 * tt) + .18 * Math.sin(TAU * fr * 3.01 * tt)) * Math.exp(-tt * 6) * Math.min(1, tt * 500)); };
const whoosh = (t, dur, g = .3, pan = 0) => { let y = 0; voice(t, dur, g, pan, .2, 0, tt => { const k = tt / dur, c = 400 + 5000 * Math.sin(Math.PI * k), a = 1 - Math.exp(-TAU * c / SR); y += a * (noise() - y); return y * Math.pow(Math.sin(Math.PI * k), 2); }); };
const thud = (t, g = .6, pan = 0) => { let p = 0; voice(t, .3, g, pan, .25, 0, tt => { p += (60 + 120 * Math.exp(-tt * 40)) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 14) + noise() * Math.exp(-tt * 90) * .3; }); };
const err = (t, g = .2, pan = 0) => [0, .09].forEach((o, i) => voice(t + o, .08, g, pan, .2, 0, tt => Math.sign(Math.sin(TAU * (i ? 311 : 392) * tt)) * .5 * Math.min(1, (0.08 - tt) * 60)));
const popS = (t, g = .25, pan = 0) => { let p = 0; voice(t, .12, g, pan, .2, 0, tt => { p += (500 + 2600 * tt) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 28); }); };

// ── 0–2 s: gancho ──
pad(0, [33, 45], 2, .16, false, 500);
[.08, .30, .54, 1.0, 1.30].forEach((t, i) => thud(t, i === 2 ? .8 : .55));
whoosh(1.7, .5, .35);
// ── 2–8 s: tensión ──
pad(2, [33, 34, 45], 6, .13, false, 700);
for (let t = 2; t < 5; t += .25) tick(t, .1, (t * 4 % 2 ? .4 : -.4));
for (let t = 5; t < 7.5; t += .125) tick(t, .1 + (t - 5) * .03, (t * 8 % 2 ? .5 : -.5));
[2, 2.5, 3, 3.5].forEach(t => { whoosh(t - .12, .3, .2); thud(t + .1, .7); });
[4.5, 4.9, 5.25, 5.55, 5.8, 6, 6.2, 6.38, 6.54, 6.68, 6.8, 6.92, 7.02, 7.12].forEach((t, i) => thud(t + .06, .32, i % 2 ? .5 : -.5));
[4.5, 5.0, 5.5, 6.1].forEach((t, i) => err(t, .22, i % 2 ? .4 : -.4));
riser(5.6, 2.4, .4); whoosh(7.45, .55, .5);
// ── 8–18 s: el giro, con ritmo ──
const CH = { Am: [57, 60, 64], F: [53, 57, 60], C: [48, 55, 64], G: [55, 59, 62] }, ROOT = { Am: 33, F: 29, C: 36, G: 31 };
const groove = (t0, ch, o = {}) => {
  pad(t0, CH[ch], 2, .075, true);
  for (let b = 0; b < 4; b++) {
    const t = t0 + b * .5;
    if (!o.noKick) kick(t, .95);
    if (!o.noClap && b % 2) clap(t, .34);
    hat(t + .25, .16); if (!o.light) { hat(t + .125, .06, -.3); hat(t + .375, .06, .4); }
    if (!o.noBass) { bass(t, ROOT[ch], .2, .42); bass(t + .25, ROOT[ch] + 12, .18, .3); }
    if (!o.noPluck) [0, 1, 2, 3].forEach(s => { const n = CH[ch][[0, 2, 1, 2][s]] + (s === 3 || b === 3 ? 24 : 12); pluck(t + s * .125, n, .12, s % 2 ? .45 : -.45); });
  }
};
impact(8, 1); ['Am', 'F', 'C', 'G', 'Am'].forEach((c, i) => groove(8 + i * 2, c));
[9.4, 12.3, 15.15].forEach(t => whoosh(t - .05, .45, .3));
[76, 79, 81, 84].forEach((n, i) => ding(10.9 + i * .35, n, .26, i % 2 ? .3 : -.3));
thud(13.85, .9); ding(13.87, 84, .22); ding(14.2, 88, .22);
popS(15.6, .25, -.4); popS(16.62, .25, .4); popS(17.0, .25, .4);
// ── 18–24 s: la oferta ──
whoosh(17.75, .45, .35);
groove(18, 'F', { noKick: true, noClap: true, light: true, noBass: true }); kick(18, .9);
groove(20, 'C', { light: true }); groove(22, 'G');
// el compás de 18–20 lleva bombo desde la primera tarjeta
[19, 19.5].forEach(t => kick(t, .9));
[19, 20, 21].forEach((t, i) => { whoosh(t - .2, .35, .28, .5); thud(t + .08, .8); ding(t + .1, [72, 76, 79][i], .22); });
for (let t = 23; t < 24; t += .125) clap(t, .1 + (t - 23) * .3);
riser(22.4, 1.6, .38);
// ── 24–30 s: cierre ──
impact(24, 1);
groove(24, 'F'); groove(26, 'C');
pad(28, CH.C.concat(72), 1.6, .1, false); bass(28, 36, 1.2, .4); kick(28, .9);
[76, 79, 84].forEach((n, i) => pluck(28 + i * .25, n, .16, [-.4, .4, 0][i]));
ding(24.8, 72, .2); ding(25.05, 79, .22); ding(26.0, 84, .26); ding(28.75, 88, .2);

// Eco (corchea con puntillo) y reverberación sencilla.
const dN = Math.round(.375 * SR); for (let i = dN; i < N; i++) DLY[i] += DLY[i - dN] * .38;
for (let i = dN; i < N; i++) { const s = DLY[i - dN] * .5; if (i % 2) L[i] += s; else L[i] += s * .6; R[i] += s * ((i >> 1) % 2 ? 1 : .8); REV[i] += s * .2; }
const combs = [1557, 1617, 1491, 1422].map(d => ({ d, b: new Float32Array(d), i: 0, f: 0 }));
const aps = [225, 556].map(d => ({ d, b: new Float32Array(d), i: 0 }));
for (let i = 0; i < N; i++) {
  let s = 0; for (const c of combs) { const y = c.b[c.i]; c.f = y * .75 + c.f * .25; c.b[c.i] = REV[i] + c.f * .8; c.i = (c.i + 1) % c.d; s += y; }
  for (const a of aps) { const y = a.b[a.i]; const x = s + y * .5; a.b[a.i] = x; a.i = (a.i + 1) % a.d; s = y - x * .5; }
  L[i] += s * .07; R[i] += s * .07 * (i % 2 ? 1 : .96);
}
// Mezcla final: saturación suave, normalizar y apagar el final.
let pk = 0; for (let i = 0; i < N; i++) { L[i] = Math.tanh(L[i] * .9); R[i] = Math.tanh(R[i] * .9); pk = Math.max(pk, Math.abs(L[i]), Math.abs(R[i])); }
const buf = Buffer.alloc(44 + N * 4);
buf.write('RIFF', 0); buf.writeUInt32LE(36 + N * 4, 4); buf.write('WAVEfmt ', 8); buf.writeUInt32LE(16, 16); buf.writeUInt16LE(1, 20); buf.writeUInt16LE(2, 22);
buf.writeUInt32LE(SR, 24); buf.writeUInt32LE(SR * 4, 28); buf.writeUInt16LE(4, 32); buf.writeUInt16LE(16, 34); buf.write('data', 36); buf.writeUInt32LE(N * 4, 40);
for (let i = 0; i < N; i++) { const t = i / SR, fade = Math.min(1, (DUR - t) / .7), k = .89 / pk * fade; buf.writeInt16LE(Math.round(L[i] * k * 32767), 44 + i * 4); buf.writeInt16LE(Math.round(R[i] * k * 32767), 46 + i * 4); }
fs.writeFileSync(process.argv[2] || 'pista.wav', buf); console.log('pico', pk.toFixed(3));
