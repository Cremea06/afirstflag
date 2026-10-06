# wasm-src (CHG-060 prototype)

Source for the two WebAssembly engines behind "Run page in Rust" and "Run page in C".
The host has no compilers, so the built files in `/engine/` are committed and served as static files.

- `rust/` Rust (no_std, no crates, no wasm-bindgen) -> `engine/aff-addr-rust.wasm`
- `c/` C11 (freestanding, no libc, no Emscripten, no wasi-sdk) -> `engine/aff-addr-c.wasm`
- `SPEC.md` the rules both follow (and the JS reference in `engine/aff-engine.js`)
- `build.sh` rebuild both (needs rustc + wasm32-unknown-unknown std, clang, lld, binaryen, wabt)
- `test/` vectors, Node test (`node test/test-node.mjs`), local server (`python3 test/serve.py 8060`)

On Debian: `apt install clang lld libstd-rust-dev-wasm32 binaryen wabt`.
With rustup instead: `rustup target add wasm32-unknown-unknown` (and `wasm-ld` still on PATH, or delete `rust/.cargo/config.toml`).
