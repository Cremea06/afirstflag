# Technology attribution

Public thank-you list for the stack under afirstflag.com and Eagles Nest.
The homepage drawer “Built With Gratitude” is the short version of this file.
Last reviewed: 2026-10-02 (CHG-059), against Shop `main` d3ea766 and Nest `main` 46eeca9.

## Foundational

- HTML, CSS, JavaScript: WHATWG / W3C / browser vendors
- Node.js: OpenJS Foundation, MIT (chat server runtime)
- Web APIs, WebRTC: standards bodies (chat voice/video uses WebRTC)
- PHP: The PHP Group / PHP Foundation, PHP License 3.01 (shop API: visit counter, collaborators, flag stats, Stripe webhook)
- PowerShell: Microsoft + community, MIT for PowerShell 7 (runs `tools/offline-chatroom.ps1`; Windows PowerShell 5.1 ships with Windows)
- WebAssembly: W3C standard (runs the `/rust/` and `/c/` address checks in the visitor's browser)
- Rust: Rust Project / Rust Foundation, MIT or Apache-2.0 (language of `wasm-src/rust/`; Rust `core` and `compiler_builtins` code is compiled into `engine/aff-addr-rust.wasm`)
- C: ISO C11 (language of `wasm-src/c/`; no C library is linked into `engine/aff-addr-c.wasm`)

## Open source (chat / server)

- Express: MIT
- Socket.IO (server + browser client): MIT
- cors: MIT (expressjs/cors; lets the homepage call the chat API)
- jsonwebtoken: MIT (auth0; single-use Nest World pass, `/nest` to `/world`)
- Nodemailer: MIT-0 (sign-in code email)
- dotenv: BSD-2-Clause
- three.js: MIT (r170, vendored as `public/world/three.min.js` for the Nest World courtyard)
- PM2: AGPL-3.0 (ops; keeps the chat server running)
- nginx: BSD-2-Clause (HTTPS front for the chat server)

## Build tools (shop WebAssembly engines, run on the build box only)

- Clang / LLVM / LLD: LLVM Project, Apache-2.0 WITH LLVM-exception (compiles the C engine; `wasm-ld` links both .wasm files)
- Binaryen: WebAssembly Community Group, Apache-2.0 (`wasm-opt -Oz` shrinks both .wasm files)
- WABT: WebAssembly Community Group, Apache-2.0 (`wasm2wat` check in `wasm-src/build.sh` that the modules have no imports)
- Not used: wasm-bindgen, wasm-pack, Emscripten, wasi-sdk, any crates.io crate

## Data (shop visitor country pill, CHG-076)

- DB-IP IP to Country Lite: DB-IP (Eris Networks), CC BY 4.0. Release 2026-10, repacked by `tools/geo-build/build.py`
  into `geo/ip-country.bin` and looked up on our own server by `api/geo-country.php`, so visitor addresses are not
  sent to DB-IP or anyone else. Attribution link "IP Geolocation by DB-IP" (https://db-ip.com) is on the homepage
  in Built With Gratitude.
- ISO 3166-1 alpha-2 country codes (ISO), with short English names checked against Debian iso-codes 4.18.0
  (`tools/geo-build/country-names.tsv`; plus XK for Kosovo, which DB-IP uses).

## Services

- Stripe: payments (Buy Flag Payment Link) and webhook for flag inventory
- mempool.space: public Bitcoin balance data (address search, Confirmed balance chip); explorer code AGPL-3.0
- xAI: Neagle replies in chat (Grok model via the xAI API)
- Open Library (Internet Archive): book search in the Literature drawer, asked from the visitor's browser (CHG-088).
  Catalog data CC0 / public domain; covers from covers.openlibrary.org under their cover guidelines.
- GitHub: source hosting
- Namecheap: domain, DNS, mail forwarding, and shared web hosting for afirstflag.com
- Let's Encrypt (ISRG): TLS certificate for the chat site
- SMTP provider: delivers sign-in codes through Nodemailer
- Google STUN: WebRTC NAT assist (terms: review open)

Sponsors (money) are separate from this list.
