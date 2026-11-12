// Pista del tercer video (128 BPM, un compás = 1,875 s, en sol menor): la cadena que se
// rompe, el precio que cae como en una tragamonedas, lo que cubre, la garantía y el cierre. Los golpes caen en los mismos instantes que la animación.
// Uso: node audio.mjs salida.wav
import fs from 'fs';
const SR = 44100, DUR = 30, N = SR * DUR, BT = .46875, BAR = 1.875, S = BT / 4;
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
const pad = (t, notes, dur, g, duck, cut = 1100) => notes.forEach((n, k) => { let p = [0, 0, 0]; const f = lp(cut), fr = midi(n); voice(t, dur + .6, g, (k % 2 ? .5 : -.5), .3, 0, tt => { let s = 0; [.994, 1, 1.007].forEach((d, i) => { p[i] += fr * d / SR; s += saw(p[i]); }); const e = Math.min(1, tt / .25) * Math.min(1, Math.max(0, (dur + .6 - tt) / .6)); const dk = duck ? 1 - .6 * Math.exp(-(((t + tt) % BT)) * 9) : 1; return f(s / 3) * e * dk; }); });
const riser = (t, dur, g = .35) => { let y = 0, p = 0; voice(t, dur, g, 0, .2, 0, tt => { const k = tt / dur, c = 300 + 9000 * k * k, a = 1 - Math.exp(-TAU * c / SR); y += a * (noise() - y); p += (200 + 900 * k * k) / SR; return (y * 1.2 + Math.sin(TAU * p) * .25) * k * k; }); };
const impact = (t, g = 1) => { let p = 0; const f = lp(3200); voice(t, 2.4, g, 0, .5, 0, tt => { p += (30 + 70 * Math.exp(-tt * 9)) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 2.6) * 1.1 + f(noise()) * Math.exp(-tt * 3.2) * .5; }); };
const tick = (t, g = .12, pan = 0) => voice(t, .03, g, pan, .15, 0, tt => Math.sin(TAU * 2300 * tt) * Math.exp(-tt * 220));
const ding = (t, n, g = .3, pan = 0) => { const fr = midi(n); voice(t, .9, g, pan, .5, .3, tt => (Math.sin(TAU * fr * tt) + .4 * Math.sin(TAU * fr * 2 * tt) + .18 * Math.sin(TAU * fr * 3.01 * tt)) * Math.exp(-tt * 6) * Math.min(1, tt * 500)); };
const whoosh = (t, dur, g = .3, pan = 0) => { let y = 0; voice(t, dur, g, pan, .2, 0, tt => { const k = tt / dur, c = 400 + 5000 * Math.sin(Math.PI * k), a = 1 - Math.exp(-TAU * c / SR); y += a * (noise() - y); return y * Math.pow(Math.sin(Math.PI * k), 2); }); };
const thud = (t, g = .6, pan = 0) => { let p = 0; voice(t, .3, g, pan, .25, 0, tt => { p += (60 + 120 * Math.exp(-tt * 40)) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 14) + noise() * Math.exp(-tt * 90) * .3; }); };
const err = (t, g = .2, pan = 0) => [0, .09].forEach((o, i) => voice(t + o, .08, g, pan, .2, 0, tt => Math.sign(Math.sin(TAU * (i ? 311 : 392) * tt)) * .5 * Math.min(1, (0.08 - tt) * 60)));
const popS = (t, g = .25, pan = 0) => { let p = 0; voice(t, .12, g, pan, .2, 0, tt => { p += (500 + 2600 * tt) / SR; return Math.sin(TAU * p) * Math.exp(-tt * 28); }); };

// Instrumentos propios de esta pista.
const tock = (t, f, g = .14, pan = 0) => voice(t, .05, g, pan, .25, 0, tt => Math.sin(TAU * f * tt) * Math.exp(-tt * 130));
const buzz = (t, g = .5) => { const f = lp(520); voice(t, .34, g, 0, .12, 0, tt => f(Math.sign(Math.sin(TAU * 152 * tt))) * (.55 + .45 * Math.sin(TAU * 31 * tt)) * Math.min(1, tt * 90) * Math.min(1, (.34 - tt) * 40)); };
const chime = (t, a, b, g = .2, pan = 0) => { ding(t, a, g, pan); ding(t + .1, b, g * 1.05, -pan); };
const bird = (t, g = .1, pan = 0, f0 = 3000) => [0, .11, .2].forEach((o, i) => { let p = 0; voice(t + o, .08, g, pan, .5, .25, tt => { p += (f0 + i * 260 + 1300 * Math.sin(Math.PI * tt / .08)) / SR; return Math.sin(TAU * p) * Math.pow(Math.sin(Math.PI * tt / .08), 2); }); });
const ohat = (t, g = .11, pan = .15) => { const f = hp(6200); voice(t, .2, g, pan, .12, 0, tt => f(noise()) * Math.exp(-tt * 20)); };
const snare = (t, g = .3) => { const a = hp(1200), b = lp(7000); voice(t, .16, g, 0, .3, 0, tt => b(a(noise())) * Math.exp(-tt * 30) + Math.sin(TAU * 190 * tt) * Math.exp(-tt * 45) * .5); };
const key = (t, g = .1, pan = 0) => { const f = hp(2600); voice(t, .025, g, pan, .1, 0, tt => f(noise()) * Math.exp(-tt * 260)); };
const stab = (t, notes, g = .09) => notes.forEach((n, k) => { let p = 0, q = 0; const f = lp(2600), fr = midi(n + 12); voice(t, .22, g, k % 2 ? .35 : -.35, .3, .35, tt => { p += fr / SR; q += fr * 1.008 / SR; return f((saw(p) + saw(q)) * .5) * Math.exp(-tt * 16) * Math.min(1, tt * 400); }); });

const CH = { Gm: [55, 58, 62], Eb: [55, 58, 63], Bb: [53, 58, 62], F: [53, 57, 60], Cm: [55, 60, 63] }, ROOT = { Gm: 43, Eb: 39, Bb: 46, F: 41, Cm: 36 };
const groove = (t0, ch, o = {}) => {
  pad(t0, CH[ch], BAR, .07, true);
  if (!o.noStab) { stab(t0 + BT * 1.5, CH[ch]); stab(t0 + BT * 3.25, CH[ch], .07); }
  for (let b = 0; b < 4; b++) {
    const t = t0 + b * BT;
    if (!o.noKick) kick(t, .95);
    if (!o.noClap && b % 2) clap(t, .34);
    ohat(t + 2 * S, .1); if (!o.light) { hat(t + S, .05, -.3); hat(t + 3 * S, .06, .4); }
    if (!o.noBass) { bass(t + 2 * S, ROOT[ch], .19, .46); if (b % 2) bass(t + 3 * S, ROOT[ch] + 12, .1, .28); }
    if (!o.noPluck) [0, 1, 2, 3].forEach(s => pluck(t + s * S, CH[ch][[0, 2, 1, 2][s]] + 12 + (b === 3 && s > 1 ? 12 : 0), .11, s % 2 ? .45 : -.45));
  }
};
const roll = (a, b, g0 = .08, g1 = .34) => { for (let t = a; t < b - .001; t += (t - a) / (b - a) > .5 ? S / 2 : S) snare(t, g0 + (g1 - g0) * (t - a) / (b - a)); };
// La cadena: golpe metálico inarmónico.
const clank = (t, g = .3, pan = 0) => { const f = hp(1800); voice(t, .5, g, pan, .4, .2, tt => (Math.sin(TAU * 523 * tt) * .5 + Math.sin(TAU * 1307 * tt) * .35 + Math.sin(TAU * 2211 * tt) * .25) * Math.exp(-tt * 9) + f(noise()) * Math.exp(-tt * 60) * .6); };
const slot = (t, n, g = .09, pan = 0) => { const fr = midi(n); voice(t, .05, g, pan, .1, 0, tt => Math.sign(Math.sin(TAU * fr * tt)) * .5 * Math.exp(-tt * 70)); };

// ── 0–3,75 s: la EPS encadenada ──
pad(0, [31, 38], 3.75, .18, false, 420); pad(0, [55, 58], 3.75, .03, false, 900);
for (let b = 0; b < 8; b++) { kick(b * BT, b % 2 ? .35 : .55); tock(b * BT + 2 * S, 1500, .06, b % 2 ? .3 : -.3); }
thud(.03, .55); thud(.47, .45); thud(.94, .6);
popS(.5, .18); thud(1.2, .5); whoosh(1.0, .3, .25); thud(1.5, .7);
[1.875, 2.34375, 2.8125].forEach((t, i) => { clank(t, .34, i % 2 ? .35 : -.35); thud(t, .8); err(t + .02, .1); });
riser(2.85, .9, .42); clank(3.28, .4); whoosh(3.28, .45, .45); impact(3.33, .35);
// ── 3,75–5,63 s: EPS sin pensión, y el precio cuenta ──
impact(3.75, .95); pad(3.75, CH.Gm, BAR, .085, true);
for (let b = 0; b < 2; b++) { kick(3.75 + b * BT, .9); ohat(3.75 + b * BT + 2 * S, .08); }
bass(3.75, 43, .4, .45);
for (let t = 4.6875, i = 0; t < 5.6; t += S / 2, i++) slot(t, 72 + (i % 8) + Math.floor(i / 8) * 2, .07 + i * .002, i % 2 ? .3 : -.3);
riser(4.7, .93, .32);
// ── 5,63–9,38 s: el golpe del precio y lo que incluye ──
impact(5.625, 1); clap(5.625, .45); chime(5.64, 86, 91, .26); chime(5.9, 91, 98, .18);
groove(5.625, 'Eb'); groove(7.5, 'Bb');
[6.5625, 7.03125, 7.5].forEach((t, i) => { whoosh(t - .1, .25, .22, i % 2 ? -.5 : .5); popS(t + .05, .24, i % 2 ? -.3 : .3); ding(t + .06, [79, 82, 86][i], .2); });
riser(8.6, .78, .3); whoosh(9.15, .3, .35);
// ── 9,38–15 s: te cubre ──
impact(9.375, .7);
groove(9.375, 'Gm'); groove(11.25, 'Eb'); groove(13.125, 'Bb');
[9.84375, 10.3125, 10.78125, 11.25].forEach((t, i) => { thud(t, .7, i % 2 ? .35 : -.35); ding(t + .02, [74, 77, 79, 82][i], .22, i % 2 ? .3 : -.3); });
stab(12.65625, [62, 67, 70], .12); ding(12.66, 86, .2);
roll(14.0625, 15, .06, .34); riser(14.0, 1.0, .34);
// ── 15–18,75 s: te afiliamos hoy ──
impact(15, .75);
groove(15, 'F'); groove(16.875, 'Gm');
[15.9375, 16.875].forEach((t, i) => { whoosh(t - .2, .32, .26, -.5); thud(t + .02, .8, -.2); chime(t + .03, [79, 84][i], [84, 89][i], .2); });
[17.8125, 18.046875, 18.28125].forEach(t => clap(t, .3));
roll(18.28125, 18.75, .1, .36); riser(17.8, .95, .38);
// ── 18,75–24,38 s: la garantía (cita médica y medicamentos) ──
impact(18.75, .9); thud(18.78, 1); clank(18.78, .2);
[67, 74, 79].forEach((n, i) => ding(18.82 + i * .08, n, .22, [-.3, .3, 0][i]));
groove(18.75, 'Bb', { light: true }); groove(20.625, 'Eb'); groove(22.5, 'F');
[19.6875, 20.15625, 20.625].forEach((t, i) => { popS(t, .16, i % 2 ? .3 : -.3); ding(t + .02, [74, 77, 79][i], .14); });
thud(21.5625, .9); stab(21.5625, [65, 69, 72], .13); chime(21.58, 84, 89, .24); impact(21.5625, .4);
popS(22.03125, .2); thud(22.5, .5); ding(22.97, 91, .14, .3);
roll(23.90625, 24.375, .08, .38); riser(23.3, 1.07, .4);
// ── 24,38–30 s: cierre ──
impact(24.375, 1);
groove(24.375, 'Eb'); groove(26.25, 'F');
popS(26.71875, .3); chime(26.73, 86, 91, .22); ding(27.4, 94, .14, .3);
pad(28.125, [46, 53, 58, 62, 65, 70], 1.5, .1, false, 1800); bass(28.125, 46, 1.25, .42); kick(28.125, .95); clap(28.125, .3);
[70, 74, 77, 82].forEach((n, i) => pluck(28.125 + i * BT / 2, n, .16, [-.4, .4, -.2, .2][i]));
ding(28.6, 86, .2);

// Eco (corchea con puntillo) y reverberación sencilla.
const dN = Math.round(.75 * BT * SR); for (let i = dN; i < N; i++) DLY[i] += DLY[i - dN] * .38;
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
