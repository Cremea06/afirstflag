//! afirstflag.com "Run page in Rust" engine (CHG-060 prototype).
//!
//! Pure computation only: no imports, no DOM, no network. JavaScript writes the
//! visitor's text into BUF, calls aff_check(len), and gets a result code back.
//! The rules are specified once in wasm-src/SPEC.md and implemented three times
//! (this file, wasm-src/c/aff_addr.c, engine/aff-engine.js). The test suite
//! checks that all three agree.
#![no_std]

use core::panic::PanicInfo;

#[panic_handler]
fn panic(_: &PanicInfo) -> ! {
    core::arch::wasm32::unreachable()
}

const BUF_LEN: usize = 1024;
static mut BUF: [u8; BUF_LEN] = [0; BUF_LEN];
static mut OUT: [u8; 32] = [0; 32];

#[no_mangle]
pub extern "C" fn aff_abi() -> i32 {
    1
}

#[no_mangle]
pub extern "C" fn aff_buf() -> *mut u8 {
    core::ptr::addr_of_mut!(BUF) as *mut u8
}

#[no_mangle]
pub extern "C" fn aff_buf_len() -> i32 {
    BUF_LEN as i32
}

#[no_mangle]
pub extern "C" fn aff_check(len: i32) -> i32 {
    if len < 0 || len as usize > BUF_LEN {
        return -2;
    }
    let s = unsafe { &(*core::ptr::addr_of!(BUF))[..len as usize] };
    check(s)
}

#[no_mangle]
pub extern "C" fn aff_sha256(len: i32) -> *const u8 {
    let n = if len < 0 || len as usize > BUF_LEN { 0 } else { len as usize };
    let s = unsafe { &(*core::ptr::addr_of!(BUF))[..n] };
    let h = sha256(s);
    unsafe {
        let out = &mut *core::ptr::addr_of_mut!(OUT);
        out.copy_from_slice(&h);
        out.as_ptr()
    }
}

// ---------------------------------------------------------------- SHA-256

const K: [u32; 64] = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
];

fn compress(h: &mut [u32; 8], block: &[u8]) {
    let mut w = [0u32; 64];
    for i in 0..16 {
        w[i] = u32::from_be_bytes([block[4 * i], block[4 * i + 1], block[4 * i + 2], block[4 * i + 3]]);
    }
    for i in 16..64 {
        let s0 = w[i - 15].rotate_right(7) ^ w[i - 15].rotate_right(18) ^ (w[i - 15] >> 3);
        let s1 = w[i - 2].rotate_right(17) ^ w[i - 2].rotate_right(19) ^ (w[i - 2] >> 10);
        w[i] = w[i - 16].wrapping_add(s0).wrapping_add(w[i - 7]).wrapping_add(s1);
    }
    let (mut a, mut b, mut c, mut d, mut e, mut f, mut g, mut hh) =
        (h[0], h[1], h[2], h[3], h[4], h[5], h[6], h[7]);
    for i in 0..64 {
        let s1 = e.rotate_right(6) ^ e.rotate_right(11) ^ e.rotate_right(25);
        let ch = (e & f) ^ (!e & g);
        let t1 = hh.wrapping_add(s1).wrapping_add(ch).wrapping_add(K[i]).wrapping_add(w[i]);
        let s0 = a.rotate_right(2) ^ a.rotate_right(13) ^ a.rotate_right(22);
        let maj = (a & b) ^ (a & c) ^ (b & c);
        let t2 = s0.wrapping_add(maj);
        hh = g;
        g = f;
        f = e;
        e = d.wrapping_add(t1);
        d = c;
        c = b;
        b = a;
        a = t1.wrapping_add(t2);
    }
    h[0] = h[0].wrapping_add(a);
    h[1] = h[1].wrapping_add(b);
    h[2] = h[2].wrapping_add(c);
    h[3] = h[3].wrapping_add(d);
    h[4] = h[4].wrapping_add(e);
    h[5] = h[5].wrapping_add(f);
    h[6] = h[6].wrapping_add(g);
    h[7] = h[7].wrapping_add(hh);
}

fn sha256(data: &[u8]) -> [u8; 32] {
    let mut h: [u32; 8] = [
        0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19,
    ];
    let len = data.len();
    let mut i = 0;
    while i + 64 <= len {
        compress(&mut h, &data[i..i + 64]);
        i += 64;
    }
    let rem = len - i;
    let mut block = [0u8; 64];
    block[..rem].copy_from_slice(&data[i..]);
    block[rem] = 0x80;
    if rem >= 56 {
        compress(&mut h, &block);
        block = [0u8; 64];
    }
    let bits = (len as u64).wrapping_mul(8);
    block[56..].copy_from_slice(&bits.to_be_bytes());
    compress(&mut h, &block);
    let mut out = [0u8; 32];
    for j in 0..8 {
        out[4 * j..4 * j + 4].copy_from_slice(&h[j].to_be_bytes());
    }
    out
}

// ---------------------------------------------------------------- helpers

const B58: &[u8; 58] = b"123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz";
const B32: &[u8; 32] = b"qpzry9x8gf2tvdw0s3jn54khce6mua7l";

fn b58_val(c: u8) -> i32 {
    let mut i = 0;
    while i < 58 {
        if B58[i] == c {
            return i as i32;
        }
        i += 1;
    }
    -1
}

fn b32_val(c: u8) -> i32 {
    let mut i = 0;
    while i < 32 {
        if B32[i] == c {
            return i as i32;
        }
        i += 1;
    }
    -1
}

fn lower(c: u8) -> u8 {
    if c.is_ascii_uppercase() { c + 32 } else { c }
}

fn starts_ci(s: &[u8], p: &[u8]) -> bool {
    if s.len() < p.len() {
        return false;
    }
    for i in 0..p.len() {
        if lower(s[i]) != p[i] {
            return false;
        }
    }
    true
}

fn is_ws(c: u8) -> bool {
    c == b' ' || c == b'\t' || c == b'\n' || c == b'\r' || c == 0x0b || c == 0x0c
}

fn is_hex(c: u8) -> bool {
    c.is_ascii_digit() || (b'a'..=b'f').contains(&c) || (b'A'..=b'F').contains(&c)
}

// SPEC step 2: private key material (seed words, extended private key, raw hex key, WIF).
fn looks_private(s: &[u8]) -> bool {
    let n = s.len();
    // 12 or more words, every word ASCII letters only
    let mut words = 0;
    let mut all_alpha = true;
    let mut in_word = false;
    for &c in s {
        if is_ws(c) {
            in_word = false;
        } else {
            if !in_word {
                words += 1;
                in_word = true;
            }
            if !c.is_ascii_alphabetic() {
                all_alpha = false;
            }
        }
    }
    if words >= 12 && all_alpha {
        return true;
    }
    if starts_ci(s, b"xprv") || starts_ci(s, b"yprv") || starts_ci(s, b"zprv") || starts_ci(s, b"tprv") {
        return true;
    }
    let start = if n >= 2 && s[0] == b'0' && (s[1] == b'x' || s[1] == b'X') { 2 } else { 0 };
    if n - start == 64 && s[start..].iter().all(|&c| is_hex(c)) {
        return true;
    }
    if n >= 51 && (s[0] == b'5' || s[0] == b'K' || s[0] == b'L') && s.iter().all(|&c| b58_val(c) >= 0) {
        return true;
    }
    false
}

fn polymod_step(chk: u32, v: u32) -> u32 {
    const G: [u32; 5] = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    let top = chk >> 25;
    let mut c = ((chk & 0x1ffffff) << 5) ^ v;
    for i in 0..5 {
        if (top >> i) & 1 == 1 {
            c ^= G[i];
        }
    }
    c
}

// SPEC step 5: bech32 / bech32m, hrp "bc" only (prefix already matched).
fn bech32(s: &[u8]) -> i32 {
    let n = s.len();
    let mut has_lo = false;
    let mut has_up = false;
    for &c in s {
        if c.is_ascii_lowercase() {
            has_lo = true;
        }
        if c.is_ascii_uppercase() {
            has_up = true;
        }
    }
    if has_lo && has_up {
        return -8;
    }
    let mut d = [0u8; 90];
    let dl = n - 3;
    for i in 0..dl {
        let v = b32_val(lower(s[3 + i]));
        if v < 0 {
            return -3;
        }
        d[i] = v as u8;
    }
    if dl < 7 {
        return -7;
    }
    // hrp "bc" expanded: [3, 3, 0, 2, 3]
    let mut chk: u32 = 1;
    for v in [3u32, 3, 0, 2, 3] {
        chk = polymod_step(chk, v);
    }
    for i in 0..dl {
        chk = polymod_step(chk, d[i] as u32);
    }
    let enc = if chk == 1 {
        1
    } else if chk == 0x2bc830a3 {
        2
    } else {
        return -6;
    };
    let ver = d[0];
    if ver > 16 {
        return -7;
    }
    let mut acc: u32 = 0;
    let mut bits: u32 = 0;
    let mut olen: usize = 0;
    for i in 1..dl - 6 {
        acc = ((acc << 5) | d[i] as u32) & 0xfff;
        bits += 5;
        while bits >= 8 {
            bits -= 8;
            olen += 1;
        }
    }
    if bits >= 5 || ((acc << (8 - bits)) & 0xff) != 0 {
        return -7;
    }
    if olen < 2 || olen > 40 {
        return -7;
    }
    if ver == 0 {
        if enc != 1 {
            return -6;
        }
        return match olen {
            20 => 3,
            32 => 4,
            _ => -7,
        };
    }
    if enc != 2 {
        return -6;
    }
    if ver == 1 && olen == 32 {
        return 5;
    }
    6
}

// SPEC step 6: legacy base58check.
fn base58(s: &[u8]) -> i32 {
    let n = s.len();
    for &c in s {
        if b58_val(c) < 0 {
            return -3;
        }
    }
    if n < 26 || n > 35 {
        return -7;
    }
    let mut zeros = 0;
    while zeros < n && s[zeros] == b'1' {
        zeros += 1;
    }
    let mut num = [0u8; 40]; // little-endian big number
    let mut size = 0usize;
    for &c in s {
        let mut carry = b58_val(c) as u32;
        for j in 0..size {
            carry += (num[j] as u32) * 58;
            num[j] = (carry & 0xff) as u8;
            carry >>= 8;
        }
        while carry > 0 {
            if size >= 40 {
                return -7;
            }
            num[size] = (carry & 0xff) as u8;
            size += 1;
            carry >>= 8;
        }
    }
    if zeros + size != 25 {
        return -7;
    }
    let mut p = [0u8; 25];
    for j in 0..size {
        p[zeros + j] = num[size - 1 - j];
    }
    let h1 = sha256(&p[..21]);
    let h2 = sha256(&h1);
    if h2[..4] != p[21..25] {
        return -6;
    }
    match p[0] {
        0x00 => 1,
        0x05 => 2,
        0x6f | 0xc4 => -5,
        _ => -7,
    }
}

fn check(s: &[u8]) -> i32 {
    let n = s.len();
    if n == 0 {
        return -1;
    }
    if looks_private(s) {
        return -4;
    }
    if n > 90 {
        return -2;
    }
    for &c in s {
        if c <= 0x20 || c >= 0x7f || c == b',' || c == b';' {
            return -3;
        }
    }
    if starts_ci(s, b"bc1") {
        return bech32(s);
    }
    let others: [&[u8]; 8] = [b"tb1", b"bcrt1", b"ltc1", b"tltc1", b"lq1", b"ert1", b"tex1", b"ex1"];
    for p in others {
        if starts_ci(s, p) {
            return -5;
        }
    }
    base58(s)
}
