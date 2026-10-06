#!/usr/bin/env python3
"""CHG-060 local test server. Mimics the .htaccess rules: /rust/ and /c/ serve
/index.html, and .wasm is sent as application/wasm. Local use only."""
import http.server, os, re, sys

ROOT = os.path.abspath(os.path.join(os.path.dirname(__file__), "..", ".."))

class H(http.server.SimpleHTTPRequestHandler):
    extensions_map = {**http.server.SimpleHTTPRequestHandler.extensions_map, ".wasm": "application/wasm", ".js": "text/javascript"}
    def __init__(self, *a, **k):
        super().__init__(*a, directory=ROOT, **k)
    def translate_path(self, path):
        clean = path.split("?", 1)[0].split("#", 1)[0]
        if re.fullmatch(r"/(rust|c)/?", clean, re.I):
            path = "/index.html"
        return super().translate_path(path)
    def end_headers(self):
        self.send_header("X-Content-Type-Options", "nosniff")  # same as live .htaccess
        super().end_headers()

port = int(sys.argv[1]) if len(sys.argv) > 1 else 8060
http.server.ThreadingHTTPServer(("127.0.0.1", port), H).serve_forever()
