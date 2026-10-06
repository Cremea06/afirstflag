/*
 * afirstflag.com "Run page in C" engine (CHG-060 prototype).
 *
 * Pure computation only: no imports, no libc, no DOM, no network. JavaScript
 * writes the visitor's text into buf, calls aff_check(len), and gets a result
 * code back. The rules are specified once in wasm-src/SPEC.md and implemented
 * three times (this file, wasm-src/rust/src/lib.rs, engine/aff-engine.js).
 * The test suite checks that all three agree.
 *
 * Build: clang --target=wasm32 -std=c11 -Oz -nostdlib -ffreestanding ... (see wasm-src/build.sh)
 */
#include <stdint.h>
#include <stddef.h>

#define EXPORT(name) __attribute__((export_name(#name)))
#define BUF_LEN 1024

static uint8_t buf[BUF_LEN];
static uint8_t out[32];

/* ------------------------------------------------------------- SHA-256 */

static const uint32_t K[64] = {
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
};

static uint32_t rotr(uint32_t x, int n) { return (x >> n) | (x << (32 - n)); }

static void compress(uint32_t h[8], const uint8_t *b) {
  uint32_t w[64], a, bb, c, d, e, f, g, hh, t1, t2;
  int i;
  for (i = 0; i < 16; i++)
    w[i] = ((uint32_t)b[4*i] << 24) | ((uint32_t)b[4*i+1] << 16) | ((uint32_t)b[4*i+2] << 8) | b[4*i+3];
  for (i = 16; i < 64; i++) {
    uint32_t s0 = rotr(w[i-15], 7) ^ rotr(w[i-15], 18) ^ (w[i-15] >> 3);
    uint32_t s1 = rotr(w[i-2], 17) ^ rotr(w[i-2], 19) ^ (w[i-2] >> 10);
    w[i] = w[i-16] + s0 + w[i-7] + s1;
  }
  a = h[0]; bb = h[1]; c = h[2]; d = h[3]; e = h[4]; f = h[5]; g = h[6]; hh = h[7];
  for (i = 0; i < 64; i++) {
    t1 = hh + (rotr(e, 6) ^ rotr(e, 11) ^ rotr(e, 25)) + ((e & f) ^ (~e & g)) + K[i] + w[i];
    t2 = (rotr(a, 2) ^ rotr(a, 13) ^ rotr(a, 22)) + ((a & bb) ^ (a & c) ^ (bb & c));
    hh = g; g = f; f = e; e = d + t1; d = c; c = bb; bb = a; a = t1 + t2;
  }
  h[0] += a; h[1] += bb; h[2] += c; h[3] += d; h[4] += e; h[5] += f; h[6] += g; h[7] += hh;
}

static void sha256(const uint8_t *data, size_t len, uint8_t dst[32]) {
  uint32_t h[8] = { 0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a,
                    0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19 };
  uint8_t block[64];
  size_t i = 0, rem, j;
  uint64_t bits = (uint64_t)len * 8;
  while (i + 64 <= len) { compress(h, data + i); i += 64; }
  rem = len - i;
  for (j = 0; j < 64; j++) block[j] = (uint8_t)(j < rem ? data[i + j] : 0);
  block[rem] = 0x80;
  if (rem >= 56) {
    compress(h, block);
    for (j = 0; j < 64; j++) block[j] = 0;
  }
  for (j = 0; j < 8; j++) block[56 + j] = (uint8_t)(bits >> (56 - 8 * j));
  compress(h, block);
  for (j = 0; j < 8; j++) {
    dst[4*j] = (uint8_t)(h[j] >> 24); dst[4*j+1] = (uint8_t)(h[j] >> 16);
    dst[4*j+2] = (uint8_t)(h[j] >> 8); dst[4*j+3] = (uint8_t)h[j];
  }
}

/* ------------------------------------------------------------- helpers */

static const char B58[] = "123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
static const char B32[] = "qpzry9x8gf2tvdw0s3jn54khce6mua7l";

static int b58_val(uint8_t c) { int i; for (i = 0; i < 58; i++) if ((uint8_t)B58[i] == c) return i; return -1; }
static int b32_val(uint8_t c) { int i; for (i = 0; i < 32; i++) if ((uint8_t)B32[i] == c) return i; return -1; }
static int is_up(uint8_t c) { return c >= 'A' && c <= 'Z'; }
static int is_lo(uint8_t c) { return c >= 'a' && c <= 'z'; }
static int is_alpha(uint8_t c) { return is_up(c) || is_lo(c); }
static int is_hex(uint8_t c) { return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f') || (c >= 'A' && c <= 'F'); }
static int is_ws(uint8_t c) { return c == ' ' || c == '\t' || c == '\n' || c == '\r' || c == 0x0b || c == 0x0c; }
static uint8_t lower(uint8_t c) { return is_up(c) ? (uint8_t)(c + 32) : c; }

static int starts_ci(const uint8_t *s, size_t n, const char *p) {
  size_t i;
  for (i = 0; p[i]; i++) if (i >= n || lower(s[i]) != (uint8_t)p[i]) return 0;
  return 1;
}

/* SPEC step 2: private key material (seed words, extended private key, raw hex key, WIF). */
static int looks_private(const uint8_t *s, size_t n) {
  size_t i, start, words = 0;
  int all_alpha = 1, in_word = 0;
  for (i = 0; i < n; i++) {
    if (is_ws(s[i])) { in_word = 0; continue; }
    if (!in_word) { words++; in_word = 1; }
    if (!is_alpha(s[i])) all_alpha = 0;
  }
  if (words >= 12 && all_alpha) return 1;
  if (starts_ci(s, n, "xprv") || starts_ci(s, n, "yprv") || starts_ci(s, n, "zprv") || starts_ci(s, n, "tprv")) return 1;
  start = (n >= 2 && s[0] == '0' && (s[1] == 'x' || s[1] == 'X')) ? 2 : 0;
  if (n - start == 64) {
    int ok = 1;
    for (i = start; i < n; i++) if (!is_hex(s[i])) { ok = 0; break; }
    if (ok) return 1;
  }
  if (n >= 51 && (s[0] == '5' || s[0] == 'K' || s[0] == 'L')) {
    int ok = 1;
    for (i = 0; i < n; i++) if (b58_val(s[i]) < 0) { ok = 0; break; }
    if (ok) return 1;
  }
  return 0;
}

static uint32_t polymod_step(uint32_t chk, uint32_t v) {
  static const uint32_t G[5] = { 0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3 };
  uint32_t top = chk >> 25, c = ((chk & 0x1ffffff) << 5) ^ v;
  int i;
  for (i = 0; i < 5; i++) if ((top >> i) & 1) c ^= G[i];
  return c;
}

/* SPEC step 5: bech32 / bech32m, hrp "bc" only (prefix already matched). */
static int bech32(const uint8_t *s, size_t n) {
  uint8_t d[90];
  size_t dl = n - 3, i, olen = 0;
  int has_lo = 0, has_up = 0, enc, ver;
  uint32_t chk = 1, acc = 0, bits = 0;
  static const uint32_t HRP[5] = { 3, 3, 0, 2, 3 };
  for (i = 0; i < n; i++) { if (is_lo(s[i])) has_lo = 1; if (is_up(s[i])) has_up = 1; }
  if (has_lo && has_up) return -8;
  for (i = 0; i < dl; i++) {
    int v = b32_val(lower(s[3 + i]));
    if (v < 0) return -3;
    d[i] = (uint8_t)v;
  }
  if (dl < 7) return -7;
  for (i = 0; i < 5; i++) chk = polymod_step(chk, HRP[i]);
  for (i = 0; i < dl; i++) chk = polymod_step(chk, d[i]);
  if (chk == 1) enc = 1;
  else if (chk == 0x2bc830a3) enc = 2;
  else return -6;
  ver = d[0];
  if (ver > 16) return -7;
  for (i = 1; i < dl - 6; i++) {
    acc = ((acc << 5) | d[i]) & 0xfff;
    bits += 5;
    while (bits >= 8) { bits -= 8; olen++; }
  }
  if (bits >= 5 || ((acc << (8 - bits)) & 0xff) != 0) return -7;
  if (olen < 2 || olen > 40) return -7;
  if (ver == 0) {
    if (enc != 1) return -6;
    if (olen == 20) return 3;
    if (olen == 32) return 4;
    return -7;
  }
  if (enc != 2) return -6;
  if (ver == 1 && olen == 32) return 5;
  return 6;
}

/* SPEC step 6: legacy base58check. */
static int base58(const uint8_t *s, size_t n) {
  uint8_t num[40], p[25], h1[32], h2[32];
  size_t i, j, zeros = 0, size = 0;
  for (i = 0; i < n; i++) if (b58_val(s[i]) < 0) return -3;
  if (n < 26 || n > 35) return -7;
  while (zeros < n && s[zeros] == '1') zeros++;
  for (i = 0; i < n; i++) {
    uint32_t carry = (uint32_t)b58_val(s[i]);
    for (j = 0; j < size; j++) {
      carry += (uint32_t)num[j] * 58;
      num[j] = (uint8_t)(carry & 0xff);
      carry >>= 8;
    }
    while (carry > 0) {
      if (size >= 40) return -7;
      num[size++] = (uint8_t)(carry & 0xff);
      carry >>= 8;
    }
  }
  if (zeros + size != 25) return -7;
  for (j = 0; j < zeros; j++) p[j] = 0;
  for (j = 0; j < size; j++) p[zeros + j] = num[size - 1 - j];
  sha256(p, 21, h1);
  sha256(h1, 32, h2);
  for (j = 0; j < 4; j++) if (h2[j] != p[21 + j]) return -6;
  if (p[0] == 0x00) return 1;
  if (p[0] == 0x05) return 2;
  if (p[0] == 0x6f || p[0] == 0xc4) return -5;
  return -7;
}

static int check(const uint8_t *s, size_t n) {
  static const char *const others[8] = { "tb1", "bcrt1", "ltc1", "tltc1", "lq1", "ert1", "tex1", "ex1" };
  size_t i;
  if (n == 0) return -1;
  if (looks_private(s, n)) return -4;
  if (n > 90) return -2;
  for (i = 0; i < n; i++)
    if (s[i] <= 0x20 || s[i] >= 0x7f || s[i] == ',' || s[i] == ';') return -3;
  if (starts_ci(s, n, "bc1")) return bech32(s, n);
  for (i = 0; i < 8; i++) if (starts_ci(s, n, others[i])) return -5;
  return base58(s, n);
}

/* ------------------------------------------------------------- exports */

EXPORT(aff_abi) int aff_abi(void) { return 1; }
EXPORT(aff_buf) uint8_t *aff_buf(void) { return buf; }
EXPORT(aff_buf_len) int aff_buf_len(void) { return BUF_LEN; }
EXPORT(aff_check) int aff_check(int len) {
  if (len < 0 || len > BUF_LEN) return -2;
  return check(buf, (size_t)len);
}
EXPORT(aff_sha256) uint8_t *aff_sha256(int len) {
  sha256(buf, (len < 0 || len > BUF_LEN) ? 0 : (size_t)len, out);
  return out;
}
