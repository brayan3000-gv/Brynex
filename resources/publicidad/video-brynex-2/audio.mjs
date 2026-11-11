// Pista del segundo video (128 BPM, un compás = 1,875 s): medianoche, madrugada con ritmo,
// amanecer y cierre. Los golpes caen en los mismos instantes que la animación.
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

const CH = { Dm: [57, 62, 65], Bb: [58, 62, 65], F: [57, 60, 65], C: [55, 60, 64] }, ROOT = { Dm: 38, Bb: 34, F: 41, C: 36 };
const groove = (t0, ch, o = {}) => {
  pad(t0, CH[ch], BAR, .07, true);
  if (!o.noStab) { stab(t0 + BT * 1.5, CH[ch]); stab(t0 + BT * 3.25, CH[ch], .07); }
  for (let b = 0; b < 4; b++) {
    const t = t0 + b * BT;
    if (!o.noKick) kick(t, .95);
    if (!o.noClap && b % 2) clap(t, .34);
    ohat(t + 2 * S, .1); if (!o.light) { hat(t + S, .05, -.3); hat(t + 3 * S, .06, .4); }
    if (!o.noBass) { bass(t + 2 * S, ROOT[ch], .19, .46); if (b % 2) bass(t + 3 * S, ROOT[ch] + 12, .1, .28); }
    if (!o.noPluck) [0, 1, 2, 3].forEach(s => pluck(t + s * S, CH[ch][[0, 1, 2, 1][s]] + 12 + (b === 3 && s > 1 ? 12 : 0), .11, s % 2 ? .45 : -.45));
  }
};
const roll = (a, b, g0 = .08, g1 = .34) => { for (let t = a, i = 0; t < b - .001; t += (t - a) / (b - a) > .5 ? S / 2 : S, i++) snare(t, g0 + (g1 - g0) * (t - a) / (b - a)); };

// ── 0–3,75 s: medianoche. Un reloj, el teléfono que vibra y nadie responde ──
pad(0, [38, 45], 3.75, .17, false, 480); pad(0, [62, 69], 3.75, .03, false, 1200);
for (let b = 0; b < 8; b++) tock(b * BT, b % 2 ? 1450 : 1900, .15, b % 2 ? .3 : -.3);
[1.40625, 1.875, 2.34375].forEach((t, i) => { buzz(t, .5); chime(t + .04, 88, 93, .15, i % 2 ? .3 : -.3); });
thud(2.8125, .75); thud(3.05, .8); riser(2.8, .95, .42); whoosh(3.42, .33, .4);
// ── 3,75–5,63 s: BryNex le responde ──
impact(3.75, .95); pad(3.75, CH.Dm, BAR, .085, true);
for (let b = 0; b < 4; b++) { kick(3.75 + b * BT, .8 + b * .05); ohat(3.75 + b * BT + 2 * S, .08); }
for (let t = 3.86, i = 0; t < 4.17; t += .043, i++) key(t, .11, i % 2 ? .3 : -.3);
popS(4.21875, .3, .3); ding(4.23, 86, .22, .3); ding(4.6875, 81, .16, -.2); ding(4.80, 89, .14, .2);
bass(3.75, 38, .3, .45); bass(4.6875, 38, .2, .4);
roll(5.15625, 5.625); riser(4.6, 1.02, .3);
// ── 5,63–13,13 s: la noche trabaja sola ──
impact(5.625, .8);
['Dm', 'Bb', 'F', 'C'].forEach((c, i) => groove(5.625 + i * BAR, c));
[81, 84, 86, 88, 91, 93].forEach((n, k) => { const t = 6.09375 + k * .9375; thud(t, .32, k % 2 ? .3 : -.3); chime(t + .01, n, n + 5, .2, k % 2 ? .35 : -.35); });
roll(12.1875, 13.125, .06, .36); riser(11.6, 1.52, .38); whoosh(12.72, .42, .4);
// ── 13,13–15 s: amanece ──
impact(13.125, .7);
pad(13.125, [53, 57, 60, 65], BAR, .105, false, 1700); bass(13.125, 41, 1.6, .36);
for (let k = 0; k < 8; k++) hat(13.125 + k * BT / 2, k % 2 ? .08 : .05, k % 2 ? .3 : -.3);
ding(13.14, 77, .24); ding(13.6, 81, .18, -.3); ding(14.0625, 84, .22, .3);
[[13.38, .5, 3000], [13.82, -.5, 3400], [14.28, .35, 2800], [14.7, -.3, 3300], [15.6, .5, 3100], [16.55, -.5, 3350]].forEach(([t, p, f], i) => bird(t, i < 4 ? .1 : .05, p, f));
whoosh(14.36, .34, .3); thud(14.56, .65);
// ── 15–18,75 s: el trabajo hecho ──
groove(15, 'Bb'); groove(16.875, 'C');
[82, 86, 89].forEach((n, i) => { popS(15 + i * BT, .22, i % 2 ? .3 : -.3); ding(15.01 + i * BT, n, .25, i % 2 ? .3 : -.3); });
thud(16.40625, .5);
[16.875, 17.34375].forEach((t, i) => { whoosh(t - .12, .3, .25, .5); thud(t + .04, .7); ding(t + .05, [84, 88][i], .18); });
roll(18.28125, 18.75, .08, .3); riser(17.5, 1.25, .34);
// ── 18,75–24,38 s: tú eliges ──
impact(18.75, .9);
groove(18.75, 'Dm'); groove(20.625, 'Bb'); groove(22.5, 'C');
[19.6875, 20.625, 21.5625].forEach((t, i) => { whoosh(t - .2, .32, .26, -.5); thud(t + .02, .8, -.2); ding(t + .03, [74, 77, 81][i], .25); });
ding(22.52, 86, .2);
roll(23.4375, 24.375, .08, .38); riser(22.85, 1.52, .4);
// ── 24,38–30 s: cierre ──
impact(24.375, 1);
groove(24.375, 'Bb'); groove(26.25, 'C');
thud(25.3125, .5); thud(25.78125, .5);
popS(26.25, .3); ding(26.27, 84, .26); ding(26.5, 88, .2); ding(27.1875, 91, .16, .3);
pad(28.125, [53, 57, 60, 65, 72], 1.5, .1, false, 1800); bass(28.125, 41, 1.25, .42); kick(28.125, .95); clap(28.125, .3);
[77, 81, 84, 89].forEach((n, i) => pluck(28.125 + i * BT / 2, n, .16, [-.4, .4, -.2, .2][i]));
ding(28.6, 89, .2);

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
