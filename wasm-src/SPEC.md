# Address check spec (CHG-060, ABI 1)

One rule set, three implementations that must agree:
`wasm-src/rust/src/lib.rs`, `wasm-src/c/aff_addr.c`, and the JavaScript reference in `engine/aff-engine.js`.
Run `node wasm-src/test/test-node.mjs` after any change.

## ABI (both .wasm files)
No imports. Exports: `memory`, `aff_abi() -> 1`, `aff_buf() -> ptr` (1024-byte input buffer),
`aff_buf_len() -> 1024`, `aff_check(len) -> code`, `aff_sha256(len) -> ptr` (32-byte digest of the buffer).
JavaScript trims the input, writes its UTF-8 bytes into the buffer, then calls `aff_check`.

## Steps (first match wins)
1. Over 1024 bytes: -2. Empty: -1.
2. Private key material: -4. Any of: 12 or more whitespace-separated words that are all ASCII letters;
   starts with xprv / yprv / zprv / tprv (any case); exactly 64 hex digits, optional 0x;
   51 or more characters, first is 5, K or L, all base58.
3. Over 90 bytes: -2.
4. Any byte <= 0x20, >= 0x7f, comma or semicolon: -3.
5. Starts with bc1 (any case): bech32 path.
   Mixed case -8. Data chars outside the bech32 charset -3. Fewer than 7 data chars -7.
   Checksum constant 1 is bech32, 0x2bc830a3 is bech32m, anything else -6.
   Witness version over 16 -7. More than 4 padding bits or non-zero padding -7.
   Program length outside 2..40 -7.
   Version 0: needs bech32 (else -6); 20 bytes = 3 (P2WPKH), 32 bytes = 4 (P2WSH), else -7.
   Version 1 to 16: needs bech32m (else -6); v1 with 32 bytes = 5 (P2TR), else 6 (newer SegWit version).
6. Starts with tb1, bcrt1, ltc1, tltc1, lq1, ert1, tex1 or ex1 (any case): -5.
7. Base58 path. Char outside base58: -3. Length outside 26..35: -7. Decoded length not 25: -7.
   Double SHA-256 checksum mismatch: -6. Version 0x00 = 1 (P2PKH), 0x05 = 2 (P2SH),
   0x6f or 0xc4 = -5 (testnet), anything else -7.

## Codes
| Code | Meaning | Lookup request sent? |
|---|---|---|
| 1 | P2PKH (1...) | yes |
| 2 | P2SH (3...) | yes |
| 3 | P2WPKH (bc1q..., 42 chars) | yes |
| 4 | P2WSH (bc1q..., 62 chars) | yes |
| 5 | P2TR Taproot (bc1p...) | yes |
| 6 | Valid newer SegWit version | yes (server may reject) |
| -1 | Empty | no |
| -2 | Too long | no |
| -3 | Bad characters | no |
| -4 | Looks like a private key or seed | no |
| -5 | Testnet or other network | no |
| -6 | Checksum failed (typo) | no |
| -7 | Not a Bitcoin address | no |
| -8 | Mixed case bc1 | no |
