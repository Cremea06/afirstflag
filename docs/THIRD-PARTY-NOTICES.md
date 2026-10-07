# Third-party notices

This project uses third-party software and services. Their licenses and terms still apply.
Last reviewed: 2026-10-02 (CHG-059).

| Name | Role | Terms |
| --- | --- | --- |
| Node.js | Runtime | MIT |
| PHP | Shop API runtime | PHP License 3.01 |
| PowerShell | Offline chatroom script | MIT (PowerShell 7) |
| Express | HTTP | MIT |
| Socket.IO | Realtime (server + client) | MIT |
| cors | Cross-origin middleware | MIT |
| jsonwebtoken | Nest World pass tokens | MIT |
| Nodemailer | Mail | MIT-0 |
| dotenv | Config | BSD-2-Clause |
| three.js | 3D renderer (vendored r170) | MIT |
| PM2 | Process manager | AGPL-3.0 |
| nginx | HTTPS front for chat | BSD-2-Clause |
| Rust `core` + `compiler_builtins` | Compiled into `engine/aff-addr-rust.wasm` (Rust engine) | MIT or Apache-2.0 |
| Clang / LLVM / LLD | Build tool for both .wasm files (nothing from LLVM runtime libraries is linked) | Apache-2.0 WITH LLVM-exception |
| Binaryen (wasm-opt) | Build tool, .wasm size optimizer | Apache-2.0 |
| WABT (wasm2wat) | Build check, no imports in .wasm | Apache-2.0 |
| Stripe | Payments + webhook | Stripe ToS |
| mempool.space | Bitcoin balance data | mempool.space terms; explorer code AGPL-3.0 |
| xAI API | Chat assist (Neagle) | xAI ToS |
| GitHub | Hosting | GitHub ToS |
| Namecheap | Domain, DNS, web hosting | Namecheap ToS |
| Let's Encrypt | TLS certificate (chat) | ISRG Subscriber Agreement |
| SMTP provider | Sign-in code mail | Provider ToS |
| Google STUN | WebRTC | Google terms: review open |
| DB-IP IP to Country Lite (release 2026-10) | Data for the visitor country pill, bundled as `geo/ip-country.bin` (repacked) | CC BY 4.0, attribution required (see below) |

## DB-IP IP to Country Lite (CHG-076)

- Data: IP to Country Lite by DB-IP (Eris Networks S.A.S., France), https://db-ip.com/db/download/ip-to-country-lite
- License: Creative Commons Attribution 4.0 International, https://creativecommons.org/licenses/by/4.0/
- Attribution (required): DB-IP requires web applications to link back to DB-IP.com on pages that show or use results.
  The homepage does this with `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>` in the
  Built With Gratitude drawer (DB-IP card). Keep that link while the data is used.
- Changes we made: any gaps filled as unknown, IPv6 reduced to /64 units, private and other special-purpose ranges
  set to unknown, neighboring ranges merged, repacked as a binary file (`tools/geo-build/build.py`).
  Source release, checksums and build details: `geo/README.md`.
- The data is provided as is, without warranty; DB-IP does not endorse this site.

Do not copy this file into a product that implies those vendors endorse afirstflag.
