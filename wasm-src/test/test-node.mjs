// CHG-060: Node test for the three engines (JS reference, Rust wasm, C wasm).
// 1) fixed vectors, 2) SHA-256 vs node:crypto, 3) random mutation agreement.
// Also reports what today's Nest server shape check (copied from
// nest-live-server.js isBitcoinMainnetAddress) would accept, for comparison.
import { readFileSync } from 'node:fs';
import { createHash, randomBytes } from 'node:crypto';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const here = (p) => new URL(p, import.meta.url);
const lib = require(here('../../engine/aff-engine.js').pathname);
const enc = new TextEncoder();

async function load(file) {
  const { instance } = await WebAssembly.instantiate(readFileSync(here(file)), {});
  return lib.wrapWasm(instance);
}
const engines = {
  js: { check: lib.jsCheck, sha256: lib.jsSha256 },
  rust: await load('../../engine/aff-addr-rust.wasm'),
  c: await load('../../engine/aff-addr-c.wasm'),
};

// --- Nest server's current shape check (copy, for comparison only) ---
function nestAccepts(raw) {
  const s = String(raw || '').trim();
  if (!s) return false;
  if (/\s/.test(s) || s.includes(',') || s.includes(';')) return false;
  if (/^(tb1|bcrt1|lq1|ert1|tex1|ex1)/i.test(s)) return false;
  if (/^bc1/i.test(s)) {
    if (s !== s.toLowerCase() && s !== s.toUpperCase()) return false;
    const lower = s.toLowerCase();
    if (!/^bc1[qp][qpzry9x8gf2tvdw0s3jn54khce6mua7l]{6,87}$/.test(lower)) return false;
    if (lower.length < 14 || lower.length > 90) return false;
    return true;
  }
  return /^[13][1-9A-HJ-NP-Za-km-z]{25,34}$/.test(s);
}

let fail = 0;
const vectors = JSON.parse(readFileSync(here('./vectors.json')));
const rows = [];
for (const v of vectors) {
  const b = enc.encode(v.input);
  const got = Object.fromEntries(Object.entries(engines).map(([k, e]) => [k, e.check(b)]));
  const ok = got.js === v.expect && got.rust === v.expect && got.c === v.expect;
  if (!ok) fail++;
  rows.push({ ok, expect: v.expect, ...got, nest: nestAccepts(v.input), note: v.note, input: v.input.length > 40 ? v.input.slice(0, 37) + '...' : v.input });
}
console.log('VECTORS');
for (const r of rows) console.log(`${r.ok ? 'PASS' : 'FAIL'} exp=${String(r.expect).padStart(2)} js=${String(r.js).padStart(2)} rust=${String(r.rust).padStart(2)} c=${String(r.c).padStart(2)} nest=${r.nest ? 'accept' : 'reject'}  ${r.note}`);
const vecPass = rows.filter((r) => r.ok).length;
console.log(`vectors: ${vecPass}/${rows.length} pass`);
const nestTypos = rows.filter((r) => r.nest && r.expect < 0);
console.log(`Nest shape check accepts ${nestTypos.length} inputs that fail the full check: ${nestTypos.map((r) => r.note).join('; ')}`);

// SHA-256 vs node:crypto
let shaFail = 0;
for (let len = 0; len <= 1024; len++) {
  const b = randomBytes(len);
  const want = createHash('sha256').update(b).digest('hex');
  for (const [k, e] of Object.entries(engines)) {
    if (Buffer.from(e.sha256(b)).toString('hex') !== want) { shaFail++; if (shaFail < 5) console.log('SHA FAIL', k, len); }
  }
}
console.log(`sha256: lengths 0..1024 x 3 engines vs node:crypto, ${shaFail} mismatches`);
fail += shaFail;

// Random mutation agreement
const seeds = vectors.map((v) => v.input).filter((s) => s.length && s.length <= 120);
const ALPH = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz0OIl ,;';
let mut = 0, disagree = 0; const hist = {};
const N = 50000;
for (let i = 0; i < N; i++) {
  let s = seeds[i % seeds.length].split('');
  const ops = 1 + (i % 3);
  for (let k = 0; k < ops; k++) {
    const r = Math.random(), p = Math.floor(Math.random() * (s.length + 1));
    const ch = ALPH[Math.floor(Math.random() * ALPH.length)];
    if (r < 0.5 && s.length) s[Math.min(p, s.length - 1)] = ch;
    else if (r < 0.75) s.splice(p, 0, ch);
    else if (s.length) s.splice(Math.min(p, s.length - 1), 1);
    if (Math.random() < 0.1) s = s.map((c) => (Math.random() < 0.5 ? c.toUpperCase() : c));
  }
  const b = enc.encode(s.join(''));
  const a = engines.js.check(b), r = engines.rust.check(b), c = engines.c.check(b);
  hist[a] = (hist[a] || 0) + 1;
  if (a !== r || a !== c) { disagree++; if (disagree < 5) console.log('DISAGREE', JSON.stringify(s.join('')), a, r, c); }
  mut++;
}
console.log(`mutation fuzz: ${mut} inputs, ${disagree} disagreements; result histogram ${JSON.stringify(hist)}`);
fail += disagree;

// Speed (informational)
const site = enc.encode('bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl');
const gen = enc.encode('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa');
for (const [k, e] of Object.entries(engines)) {
  const t0 = performance.now();
  for (let i = 0; i < 20000; i++) { e.check(site); e.check(gen); }
  console.log(`speed ${k}: ${((performance.now() - t0) / 40000 * 1000).toFixed(2)} us per check (Node ${process.version})`);
}
console.log(fail ? `RESULT: FAIL (${fail})` : 'RESULT: ALL PASS');
process.exit(fail ? 1 : 0);
