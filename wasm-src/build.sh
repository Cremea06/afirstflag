#!/usr/bin/env bash
# CHG-060: build both engines on the box. The host (Namecheap) has no toolchains;
# the compiled .wasm files in /engine/ are committed and served as static files.
# Needs: rustc + wasm32-unknown-unknown std, clang + lld (wasm-ld), binaryen (wasm-opt).
set -euo pipefail
cd "$(dirname "$0")"
OUT=../engine

( cd rust && cargo build --release --target wasm32-unknown-unknown )
wasm-opt -Oz --strip-debug --strip-producers \
  rust/target/wasm32-unknown-unknown/release/aff_addr.wasm -o "$OUT/aff-addr-rust.wasm"

clang --target=wasm32 -std=c11 -Oz -Wall -Wextra -Werror -nostdlib -ffreestanding -fno-builtin \
  -Wl,--no-entry -Wl,--strip-all -Wl,-zstack-size=16384 -Wl,--initial-memory=131072 \
  -o c/aff_addr.wasm c/aff_addr.c
wasm-opt -Oz --strip-debug --strip-producers c/aff_addr.wasm -o "$OUT/aff-addr-c.wasm"
rm -f c/aff_addr.wasm

# No imports allowed: the modules must not be able to call anything in the page.
for f in "$OUT"/aff-addr-*.wasm; do
  if wasm2wat "$f" | grep -q "(import"; then echo "ERROR: $f has imports" >&2; exit 1; fi
  printf '%-28s %6d bytes  gzip %5d  sha256 %s\n' "$(basename "$f")" "$(stat -c %s "$f")" \
    "$(gzip -9c "$f" | wc -c)" "$(sha256sum "$f" | cut -c1-16)"
done
