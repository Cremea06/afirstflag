/*
 * CHG-060 prototype: "Run page in Rust" / "Run page in C" engine loader.
 *
 * Loaded only on the /rust/ and /c/ variants (or ?engine=rust|c). The root
 * homepage never loads this file. Everything here runs in the visitor's
 * browser; the .wasm modules have no imports, so they cannot touch the page,
 * the network, or storage. JavaScript stays in charge of the DOM and fetch.
 *
 * The same address rules live in three places that must agree
 * (wasm-src/SPEC.md): wasm-src/rust/src/lib.rs, wasm-src/c/aff_addr.c, and the
 * JavaScript reference below (used as the fallback when WebAssembly fails).
 */
(function (root) {
  "use strict";

  var VERSION = "065-1";
  var BUF_LEN = 1024;

  /* ------------------------------------------------ JavaScript reference */

  var K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ]);

  function rotr(x, n) { return (x >>> n) | (x << (32 - n)); }

  function compress(h, b, off) {
    var w = new Uint32Array(64), i, a, bb, c, d, e, f, g, hh, t1, t2;
    for (i = 0; i < 16; i++) {
      w[i] = (b[off + 4 * i] << 24) | (b[off + 4 * i + 1] << 16) | (b[off + 4 * i + 2] << 8) | b[off + 4 * i + 3];
    }
    for (i = 16; i < 64; i++) {
      var s0 = rotr(w[i - 15], 7) ^ rotr(w[i - 15], 18) ^ (w[i - 15] >>> 3);
      var s1 = rotr(w[i - 2], 17) ^ rotr(w[i - 2], 19) ^ (w[i - 2] >>> 10);
      w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
    }
    a = h[0]; bb = h[1]; c = h[2]; d = h[3]; e = h[4]; f = h[5]; g = h[6]; hh = h[7];
    for (i = 0; i < 64; i++) {
      t1 = (hh + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + w[i]) | 0;
      t2 = ((rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) + ((a & bb) ^ (a & c) ^ (bb & c))) | 0;
      hh = g; g = f; f = e; e = (d + t1) | 0; d = c; c = bb; bb = a; a = (t1 + t2) | 0;
    }
    h[0] += a; h[1] += bb; h[2] += c; h[3] += d; h[4] += e; h[5] += f; h[6] += g; h[7] += hh;
  }

  function jsSha256(data) {
    var h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    var len = data.length, i = 0, j;
    while (i + 64 <= len) { compress(h, data, i); i += 64; }
    var rem = len - i, block = new Uint8Array(64);
    block.set(data.subarray(i));
    block[rem] = 0x80;
    if (rem >= 56) { compress(h, block, 0); block.fill(0); }
    var bitsHi = Math.floor(len / 0x20000000), bitsLo = (len * 8) >>> 0;
    block[56] = bitsHi >>> 24; block[57] = bitsHi >>> 16; block[58] = bitsHi >>> 8; block[59] = bitsHi;
    block[60] = bitsLo >>> 24; block[61] = bitsLo >>> 16; block[62] = bitsLo >>> 8; block[63] = bitsLo;
    compress(h, block, 0);
    var out = new Uint8Array(32);
    for (j = 0; j < 8; j++) {
      out[4 * j] = h[j] >>> 24; out[4 * j + 1] = h[j] >>> 16; out[4 * j + 2] = h[j] >>> 8; out[4 * j + 3] = h[j];
    }
    return out;
  }

  var B58 = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
  var B32 = "qpzry9x8gf2tvdw0s3jn54khce6mua7l";
  function b58Val(c) { return B58.indexOf(String.fromCharCode(c)); }
  function b32Val(c) { return B32.indexOf(String.fromCharCode(c)); }
  function isUp(c) { return c >= 65 && c <= 90; }
  function isLo(c) { return c >= 97 && c <= 122; }
  function isAlpha(c) { return isUp(c) || isLo(c); }
  function isHex(c) { return (c >= 48 && c <= 57) || (c >= 97 && c <= 102) || (c >= 65 && c <= 70); }
  function isWs(c) { return c === 32 || c === 9 || c === 10 || c === 13 || c === 11 || c === 12; }
  function lower(c) { return isUp(c) ? c + 32 : c; }
  function startsCi(s, p) {
    if (s.length < p.length) return false;
    for (var i = 0; i < p.length; i++) if (lower(s[i]) !== p.charCodeAt(i)) return false;
    return true;
  }

  function looksPrivate(s) {
    var n = s.length, i, words = 0, allAlpha = true, inWord = false;
    for (i = 0; i < n; i++) {
      if (isWs(s[i])) { inWord = false; continue; }
      if (!inWord) { words++; inWord = true; }
      if (!isAlpha(s[i])) allAlpha = false;
    }
    if (words >= 12 && allAlpha) return true;
    if (startsCi(s, "xprv") || startsCi(s, "yprv") || startsCi(s, "zprv") || startsCi(s, "tprv")) return true;
    var start = (n >= 2 && s[0] === 48 && (s[1] === 120 || s[1] === 88)) ? 2 : 0;
    if (n - start === 64) {
      var ok = true;
      for (i = start; i < n; i++) if (!isHex(s[i])) { ok = false; break; }
      if (ok) return true;
    }
    if (n >= 51 && (s[0] === 53 || s[0] === 75 || s[0] === 76)) {
      var ok2 = true;
      for (i = 0; i < n; i++) if (b58Val(s[i]) < 0) { ok2 = false; break; }
      if (ok2) return true;
    }
    return false;
  }

  var GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
  function polymodStep(chk, v) {
    var top = chk >>> 25, c = (((chk & 0x1ffffff) << 5) ^ v) >>> 0;
    for (var i = 0; i < 5; i++) if ((top >>> i) & 1) c = (c ^ GEN[i]) >>> 0;
    return c;
  }

  function bech32(s) {
    var n = s.length, dl = n - 3, i, hasLo = false, hasUp = false, d = [], olen = 0;
    for (i = 0; i < n; i++) { if (isLo(s[i])) hasLo = true; if (isUp(s[i])) hasUp = true; }
    if (hasLo && hasUp) return -8;
    for (i = 0; i < dl; i++) {
      var v = b32Val(lower(s[3 + i]));
      if (v < 0) return -3;
      d.push(v);
    }
    if (dl < 7) return -7;
    var chk = 1, hrp = [3, 3, 0, 2, 3];
    for (i = 0; i < 5; i++) chk = polymodStep(chk, hrp[i]);
    for (i = 0; i < dl; i++) chk = polymodStep(chk, d[i]);
    var enc;
    if (chk === 1) enc = 1;
    else if (chk === 0x2bc830a3) enc = 2;
    else return -6;
    var ver = d[0];
    if (ver > 16) return -7;
    var acc = 0, bits = 0;
    for (i = 1; i < dl - 6; i++) {
      acc = ((acc << 5) | d[i]) & 0xfff;
      bits += 5;
      while (bits >= 8) { bits -= 8; olen++; }
    }
    if (bits >= 5 || ((acc << (8 - bits)) & 0xff) !== 0) return -7;
    if (olen < 2 || olen > 40) return -7;
    if (ver === 0) {
      if (enc !== 1) return -6;
      if (olen === 20) return 3;
      if (olen === 32) return 4;
      return -7;
    }
    if (enc !== 2) return -6;
    if (ver === 1 && olen === 32) return 5;
    return 6;
  }

  function base58(s) {
    var n = s.length, i, j, zeros = 0, size = 0, num = new Uint8Array(40);
    for (i = 0; i < n; i++) if (b58Val(s[i]) < 0) return -3;
    if (n < 26 || n > 35) return -7;
    while (zeros < n && s[zeros] === 49) zeros++;
    for (i = 0; i < n; i++) {
      var carry = b58Val(s[i]);
      for (j = 0; j < size; j++) {
        carry += num[j] * 58;
        num[j] = carry & 0xff;
        carry >>>= 8;
      }
      while (carry > 0) {
        if (size >= 40) return -7;
        num[size++] = carry & 0xff;
        carry >>>= 8;
      }
    }
    if (zeros + size !== 25) return -7;
    var p = new Uint8Array(25);
    for (j = 0; j < size; j++) p[zeros + j] = num[size - 1 - j];
    var h2 = jsSha256(jsSha256(p.subarray(0, 21)));
    for (j = 0; j < 4; j++) if (h2[j] !== p[21 + j]) return -6;
    if (p[0] === 0x00) return 1;
    if (p[0] === 0x05) return 2;
    if (p[0] === 0x6f || p[0] === 0xc4) return -5;
    return -7;
  }

  var OTHERS = ["tb1", "bcrt1", "ltc1", "tltc1", "lq1", "ert1", "tex1", "ex1"];

  /* bytes: Uint8Array of UTF-8. Returns the SPEC result code. */
  function jsCheck(s) {
    var n = s.length, i;
    if (n > BUF_LEN) return -2;
    if (n === 0) return -1;
    if (looksPrivate(s)) return -4;
    if (n > 90) return -2;
    for (i = 0; i < n; i++) {
      var c = s[i];
      if (c <= 0x20 || c >= 0x7f || c === 44 || c === 59) return -3;
    }
    if (startsCi(s, "bc1")) return bech32(s);
    for (i = 0; i < OTHERS.length; i++) if (startsCi(s, OTHERS[i])) return -5;
    return base58(s);
  }

  /* ------------------------------------------------ WebAssembly wrapper */

  /* Wrap an instantiated module (Rust or C, same ABI v1). */
  function wrapWasm(instance) {
    var ex = instance.exports;
    if (typeof ex.aff_abi !== "function" || ex.aff_abi() !== 1) throw new Error("abi");
    var cap = ex.aff_buf_len();
    function put(bytes) {
      var ptr = ex.aff_buf();
      new Uint8Array(ex.memory.buffer, ptr, cap).set(bytes);
    }
    return {
      check: function (bytes) {
        if (bytes.length > cap) return -2;
        put(bytes);
        return ex.aff_check(bytes.length);
      },
      sha256: function (bytes) {
        if (bytes.length > cap) throw new Error("too long");
        put(bytes);
        var p = ex.aff_sha256(bytes.length);
        return new Uint8Array(ex.memory.buffer, p, 32).slice();
      }
    };
  }

  var MESSAGES = {
    "1": "Valid legacy address (P2PKH).",
    "2": "Valid script address (P2SH).",
    "3": "Valid SegWit address (P2WPKH).",
    "4": "Valid SegWit script address (P2WSH).",
    "5": "Valid Taproot address (P2TR).",
    "6": "Valid address with a newer SegWit version. Lookup may not support it yet.",
    "-1": "Paste a public Bitcoin address.",
    "-2": "That is too long to be a Bitcoin address.",
    "-3": "That has characters a Bitcoin address cannot contain.",
    "-4": "Never paste private keys. Public address only. Nothing was sent.",
    "-5": "That is a testnet or other-network address. Bitcoin mainnet only.",
    "-6": "Checksum failed. Check the address for a typo.",
    "-7": "That doesn't look like a Bitcoin address.",
    "-8": "Mixed upper and lower case is not allowed in a bc1 address."
  };

  var lib = { VERSION: VERSION, jsCheck: jsCheck, jsSha256: jsSha256, wrapWasm: wrapWasm, MESSAGES: MESSAGES };
  if (typeof module !== "undefined" && module.exports) module.exports = lib;
  if (typeof document === "undefined") return; /* Node test harness stops here */

  /* ------------------------------------------------ page wiring (browser) */

  var ENGINES = {
    rust: { label: "Rust", url: "/engine/aff-addr-rust.wasm?v=" + VERSION },
    c: { label: "C", url: "/engine/aff-addr-c.wasm?v=" + VERSION }
  };

  function pickEngine() {
    var m = /^\/(rust|c)\/?(index\.html)?$/i.exec(location.pathname || "");
    if (m) return m[1].toLowerCase();
    var q = /[?&]engine=(rust|c)\b/i.exec(location.search || "");
    return q ? q[1].toLowerCase() : null;
  }

  var id = pickEngine();
  if (!id) return;
  var eng = ENGINES[id];
  var enc = new TextEncoder();
  function hex(u8) { var s = ""; for (var i = 0; i < u8.length; i++) s += (u8[i] < 16 ? "0" : "") + u8[i].toString(16); return s; }

  var state = { id: id, label: eng.label, mode: "loading", bytes: 0, impl: null, error: null };
  root.AFF_ENGINE = state;

  /* UI: chip + links; active engine = muted current text (same pattern as root JS) */
  var chip = document.getElementById("engine-chip");
  var jsLink = document.getElementById("engine-js-link");
  var links = document.querySelectorAll(".engine-link[data-engine]");
  for (var i = 0; i < links.length; i++) {
    if (links[i].getAttribute("data-engine") === id) {
      links[i].hidden = true;
      var cur = document.getElementById("engine-" + id + "-current");
      if (cur) cur.hidden = false;
    }
  }
  if (jsLink) jsLink.hidden = false;
  function paintChip() {
    if (!chip) return;
    var t;
    if (state.mode === "wasm") t = "Engine: " + eng.label + " (WebAssembly, " + (state.bytes / 1024).toFixed(1) + " KB)";
    else if (state.mode === "loading") t = "Engine: " + eng.label + " (loading WebAssembly)";
    else t = "Engine: JavaScript (" + eng.label + " WebAssembly did not load)";
    chip.textContent = t;
    chip.title = state.mode === "wasm"
      ? "Address checks on this page run in your browser as WebAssembly compiled from " + eng.label + ". Self-test passed."
      : (state.error ? "Fallback reason: " + state.error : "");
    chip.setAttribute("data-mode", state.mode);
    chip.hidden = false;
  }
  paintChip();

  var note = null;
  var statusEl = document.getElementById("btc-lookup-status");
  if (statusEl && statusEl.parentNode) {
    note = document.createElement("p");
    note.className = "engine-note";
    note.id = "btc-engine-note";
    note.setAttribute("aria-live", "polite");
    statusEl.parentNode.insertBefore(note, statusEl.nextSibling);
  }

  var SELF_TESTS = [
    ["bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnl", 3],
    ["1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa", 1],
    ["bc1qcergrmvap2rlnx3e7g2nl8e5seh54mymrsjfnm", -6]
  ];
  var ABC = "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad";

  function selfTest(impl) {
    if (hex(impl.sha256(enc.encode("abc"))) !== ABC) throw new Error("sha256 self-test");
    for (var k = 0; k < SELF_TESTS.length; k++) {
      if (impl.check(enc.encode(SELF_TESTS[k][0])) !== SELF_TESTS[k][1]) throw new Error("check self-test " + k);
    }
  }

  var jsImpl = { check: jsCheck, sha256: jsSha256 };

  state.ready = fetch(eng.url, { credentials: "same-origin" })
    .then(function (res) {
      if (!res.ok) throw new Error("HTTP " + res.status);
      return res.arrayBuffer();
    })
    .then(function (buf) {
      state.bytes = buf.byteLength;
      /* arrayBuffer + instantiate works even if the host sends the wrong MIME type */
      return WebAssembly.instantiate(buf, {});
    })
    .then(function (r) {
      var impl = wrapWasm(r.instance);
      selfTest(impl);
      state.impl = impl;
      state.mode = "wasm";
    })
    .catch(function (err) {
      state.error = String((err && err.message) || err);
      state.impl = jsImpl;
      state.mode = "js-fallback";
    })
    .then(function () {
      paintChip();
      try { document.documentElement.setAttribute("data-engine", state.id + ":" + state.mode); } catch (e) {}
      return state;
    });

  /* Called by the lookup form (index.html). Returns null when not ready. */
  root.AFF_PRECHECK = function (address) {
    if (!state.impl) return null;
    var bytes = enc.encode(String(address || ""));
    var t0 = performance.now();
    var code = state.impl.check(bytes);
    var ms = performance.now() - t0;
    var ok = code > 0;
    var who = state.mode === "wasm" ? eng.label + " (WebAssembly)" : "JavaScript fallback";
    if (note) {
      /* browsers round performance.now() to about 0.1 ms, so do not show false precision */
      var took = ms < 0.1 ? "under 0.1 ms" : ms.toFixed(1) + " ms";
      note.textContent = "Checked in your browser by " + who + " in " + took + ": " +
        (ok ? MESSAGES[String(code)] : "not sent to the lookup service.");
      note.setAttribute("data-code", String(code));
    }
    return { ok: ok, code: code, message: MESSAGES[String(code)] || "That doesn't look like a Bitcoin address.", ms: ms };
  };

  /* Exposed for the test page and curious visitors (DevTools). */
  state.check = function (address) { return state.impl ? state.impl.check(enc.encode(String(address))) : null; };
  state.sha256Hex = function (text) { return state.impl ? hex(state.impl.sha256(enc.encode(String(text)))) : null; };
  state.lib = lib;
})(typeof window !== "undefined" ? window : this);
