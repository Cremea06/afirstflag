#!/usr/bin/env python3
"""CHG-076: build geo/ip-country.bin and geo/country-names.php for api/geo-country.php.

Source: DB-IP "IP to Country Lite" CSV (CC BY 4.0, https://db-ip.com/db/download/ip-to-country-lite).
The site needs attribution: the homepage links "IP Geolocation by DB-IP" to https://db-ip.com
(Built With Gratitude card). Keep that link when refreshing.

Usage (run on a build machine, never on the web host; Python 3.6+, standard library only):
  python3 tools/geo-build/build.py --month 2026-10            # downloads dbip-country-lite-2026-10.csv.gz
  python3 tools/geo-build/build.py --csv path/to/dbip-country-lite-2026-10.csv.gz
Then run:  php tools/geo-build/test-lookup.php
and commit geo/ip-country.bin, geo/country-names.php and geo/README.md together.

Output format (all integers big-endian), read by geo/lookup.php:
  header, 64 bytes: "AFFGEO01", IPv4 count (uint32), IPv6 count (uint32), source tag (32 bytes, ASCII,
                    NUL padded), 16 zero bytes
  IPv4 block: count x 6 bytes  = range start (4 bytes) + country code (2 ASCII bytes)
  IPv6 block: count x 10 bytes = first 64 bits of range start (8 bytes) + country code (2 ASCII bytes)
Each block is sorted, starts at address 0 and covers the whole address space with no gaps. "ZZ" means
unknown. A range ends where the next one starts. IPv6 is kept at /64 granularity (a /64 is the smallest
block normally given to one customer); a /64 that DB-IP splits between countries goes to the country
that covers most of it. Private, loopback, documentation, multicast and other special-purpose ranges
are always written as "ZZ" (list below), whatever the source says.
"""
import argparse, csv, datetime, gzip, hashlib, io, ipaddress, os, re, struct, sys, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.dirname(os.path.dirname(HERE))
GEO = os.path.join(ROOT, 'geo')
MAGIC = b'AFFGEO01'
URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz'

# IANA special-purpose registries (RFC 6890 and updates): never a visitor's real country.
RESERVED_V4 = ['0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
               '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
               '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4']
# Everything outside 2000::/3 (global unicast) plus special blocks inside it.
RESERVED_V6 = ['::/3', '4000::/2', '8000::/1', '2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']


def read_source(args):
    if args.csv:
        raw = open(args.csv, 'rb').read()
        name = os.path.basename(args.csv)
    else:
        if not re.fullmatch(r'\d{4}-\d{2}', args.month or ''):
            sys.exit('give --month YYYY-MM or --csv FILE')
        url = URL % args.month
        print('downloading', url)
        raw = urllib.request.urlopen(url, timeout=120).read()
        name = os.path.basename(url)
    m = re.search(r'(\d{4}-\d{2})', name)
    if not m:
        sys.exit('file name must contain the release month (YYYY-MM): ' + name)
    text = gzip.decompress(raw) if raw[:2] == b'\x1f\x8b' else raw
    return name, m.group(1), hashlib.sha256(raw).hexdigest(), hashlib.md5(text).hexdigest(), text.decode('utf-8')


def parse(text):
    v4, v6 = [], []
    for row in csv.reader(io.StringIO(text)):
        if not row:
            continue
        a, b, cc = ipaddress.ip_address(row[0]), ipaddress.ip_address(row[1]), row[2].strip().upper()
        if a.version != b.version or int(a) > int(b) or not re.fullmatch(r'[A-Z]{2}', cc):
            sys.exit('bad row: %r' % row)
        (v4 if a.version == 4 else v6).append((int(a), int(b), cc))
    v4.sort()
    v6.sort()
    return v4, v6


def check_cover(segs, top, label):
    """Segments must start at 0, end at top, and touch with no gap or overlap."""
    if not segs or segs[0][0] != 0 or segs[-1][1] != top:
        sys.exit('%s does not cover the whole address space' % label)
    for x, y in zip(segs, segs[1:]):
        if y[0] != x[1] + 1:
            sys.exit('%s gap or overlap near %x' % (label, y[0]))


def fill_gaps(segs, top):
    out, nxt = [], 0
    for a, b, cc in segs:
        if a > nxt:
            out.append((nxt, a - 1, 'ZZ'))
        out.append((a, b, cc))
        nxt = b + 1
    if nxt <= top:
        out.append((nxt, top, 'ZZ'))
    return out


def to_v6_64(segs):
    """IPv6 128-bit ranges -> /64 units. Shared /64s go to the largest share."""
    M = (1 << 64) - 1
    out, contested = [], {}
    for a, b, cc in segs:
        fb, lb = a >> 64, b >> 64
        head_part, tail_part = (a & M) != 0, (b & M) != M
        if fb == lb and (head_part or tail_part):
            contested.setdefault(fb, []).append((b - a + 1, cc))
            continue
        lo, hi = fb, lb
        if head_part:
            contested.setdefault(fb, []).append((((fb << 64) | M) - a + 1, cc))
            lo = fb + 1
        if tail_part:
            contested.setdefault(lb, []).append((b - (lb << 64) + 1, cc))
            hi = lb - 1
        if lo <= hi:
            out.append((lo, hi, cc))
    for blk, parts in contested.items():
        best = max(parts, key=lambda p: p[0])  # max keeps the first on ties
        out.append((blk, blk, best[1]))
    out.sort()
    return out, len(contested)


def paint_reserved(segs, nets, shift):
    for net in nets:
        n = ipaddress.ip_network(net)
        lo, hi = int(n.network_address) >> shift, int(n.broadcast_address) >> shift
        new = []
        for a, b, cc in segs:
            if b < lo or a > hi:
                new.append((a, b, cc))
                continue
            if a < lo:
                new.append((a, lo - 1, cc))
            new.append((max(a, lo), min(b, hi), 'ZZ'))
            if b > hi:
                new.append((hi + 1, b, cc))
        segs = new
    return segs


def merge(segs):
    out = []
    for a, b, cc in segs:
        if out and out[-1][2] == cc and out[-1][1] + 1 == a:
            out[-1] = (out[-1][0], b, cc)
        else:
            out.append((a, b, cc))
    return out


def load_names():
    names = {}
    for line in open(os.path.join(HERE, 'country-names.tsv'), encoding='utf-8'):
        if line.startswith('#') or not line.strip():
            continue
        cc, name = line.rstrip('\n').split('\t')
        if not re.fullmatch(r'[A-Z]{2}', cc) or not name or '\\' in name or len(name) > 40:
            sys.exit('bad names line: %r' % line)
        names[cc] = name
    return names


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('--month')
    ap.add_argument('--csv')
    args = ap.parse_args()
    fname, month, sha, md5, text = read_source(args)
    v4, v6 = parse(text)
    rows_in = len(v4) + len(v6)
    v4, v6 = fill_gaps(v4, (1 << 32) - 1), fill_gaps(v6, (1 << 128) - 1)
    check_cover(v4, (1 << 32) - 1, 'IPv4')
    check_cover(v6, (1 << 128) - 1, 'IPv6')
    v6, shared = to_v6_64(v6)
    check_cover(v6, (1 << 64) - 1, 'IPv6 /64')
    v4 = merge(paint_reserved(v4, RESERVED_V4, 0))
    v6 = merge(paint_reserved(v6, RESERVED_V6, 64))
    check_cover(v4, (1 << 32) - 1, 'IPv4 final')
    check_cover(v6, (1 << 64) - 1, 'IPv6 final')

    names = load_names()
    used = sorted({cc for _, _, cc in v4 + v6} - {'ZZ'})
    missing = [cc for cc in used if cc not in names]
    if missing:
        sys.exit('country-names.tsv has no name for: ' + ' '.join(missing))

    tag = ('dbip-country-lite-' + month).encode('ascii')
    out = bytearray(MAGIC + struct.pack('>II', len(v4), len(v6)) + tag.ljust(32, b'\0') + b'\0' * 16)
    for a, _, cc in v4:
        out += struct.pack('>I', a) + cc.encode('ascii')
    for a, _, cc in v6:
        out += struct.pack('>Q', a) + cc.encode('ascii')
    assert len(out) == 64 + 6 * len(v4) + 10 * len(v6)
    os.makedirs(GEO, exist_ok=True)
    with open(os.path.join(GEO, 'ip-country.bin'), 'wb') as f:
        f.write(out)

    php = ["<?php", "// CHG-076: country names for api/geo-country.php. Generated by tools/geo-build/build.py from",
           "// tools/geo-build/country-names.tsv; edit the .tsv and rebuild instead of editing this file.", "return ["]
    for cc in sorted(names):
        php.append("    '%s' => '%s'," % (cc, names[cc].replace('\\', '\\\\').replace("'", "\\'")))
    php.append("];")
    with open(os.path.join(GEO, 'country-names.php'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(php) + '\n')

    binsha = hashlib.sha256(out).hexdigest()
    today = datetime.date.today().isoformat()
    readme = """# geo/ (CHG-076)

Bundled IP to country database for `api/geo-country.php` (the visitor country pill in Live-state).
The web server denies HTTP access to this folder (`geo/.htaccess`); PHP reads the files from disk.
Visitor addresses are only compared against `ip-country.bin` on this server. They are never sent to
another service, logged, or stored.

| File | What |
| --- | --- |
| `ip-country.bin` | packed ranges, format in `tools/geo-build/build.py` and `geo/lookup.php` |
| `lookup.php` | `af_geo_lookup()` binary search (no network, a few small file reads) |
| `country-names.php` | code to name map, generated from `tools/geo-build/country-names.tsv` |

## Source

- Data: DB-IP IP to Country Lite, release %(month)s (`%(fname)s`)
- Download page: https://db-ip.com/db/download/ip-to-country-lite
- Source file sha256 (as downloaded): `%(sha)s`
- Source CSV md5 (compare with the MD5SUM on the download page): `%(md5)s`
- License: Creative Commons Attribution 4.0 International (CC BY 4.0), https://creativecommons.org/licenses/by/4.0/
- Attribution (required): pages that show results link back to DB-IP. The homepage has
  `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>` in the Built With Gratitude drawer.
- Changes made to the data: any gaps filled as unknown, IPv6 reduced to /64 units (%(shared)d shared /64s go
  to the largest share), special-purpose ranges forced to unknown, neighboring ranges with the same
  country merged, repacked as binary.

## Build

- Built %(today)s by `tools/geo-build/build.py`
- Source rows: %(rows)d; IPv4 ranges: %(n4)d; IPv6 /64 ranges: %(n6)d; countries used: %(nused)d
- `ip-country.bin`: %(size)d bytes, sha256 `%(binsha)s`

Refresh (DB-IP publishes a new Lite release at the start of each month):
`python3 tools/geo-build/build.py --month YYYY-MM && php tools/geo-build/test-lookup.php`, then commit
`geo/` in one change. Each refresh adds roughly the compressed size of `ip-country.bin` to git history,
so refreshing every few months is enough for a country pill.
""" % dict(month=month, fname=fname, sha=sha, md5=md5, shared=shared, today=today, rows=rows_in,
           n4=len(v4), n6=len(v6), nused=len(used), size=len(out), binsha=binsha)
    with open(os.path.join(GEO, 'README.md'), 'w', encoding='utf-8') as f:
        f.write(readme)
    print('source   %s (sha256 %s, csv md5 %s)' % (fname, sha, md5))
    print('rows     %d in -> IPv4 %d + IPv6/64 %d out (%d shared /64s resolved)' % (rows_in, len(v4), len(v6), shared))
    print('codes    %d used, all named' % len(used))
    print('output   geo/ip-country.bin %d bytes sha256 %s' % (len(out), binsha))


if __name__ == '__main__':
    main()
