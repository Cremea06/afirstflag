# tools/geo-build (CHG-076)

Builds the bundled IP to country database used by `api/geo-country.php` (the visitor country pill in
Live-state). Run these on a build machine; the web host only needs the committed files in `geo/`.
`tools/.htaccess` blocks HTTP access to this folder.

- `build.py` downloads (or reads) a DB-IP "IP to Country Lite" CSV and writes `geo/ip-country.bin`,
  `geo/country-names.php` and `geo/README.md`. Python 3.6+, standard library only. Same input gives
  byte-identical output.
- `country-names.tsv` short English names for the 249 ISO 3166-1 alpha-2 codes plus XK (Kosovo).
  The build stops if the data uses a code that has no name here.
- `test-lookup.php` 73 checks of `geo/lookup.php` against the built file (well-known addresses,
  range edges, private and reserved ranges, junk input, missing or damaged file). `php tools/geo-build/test-lookup.php`
- `test-visited.php` (CHG-077) checks of `geo/visited.php`, the countries-seen set behind "Remaining countries"
  (the 249 codes, add once, invalid codes never written, damaged file repair), in a temp folder. `php tools/geo-build/test-visited.php`

## Refresh

```
python3 tools/geo-build/build.py --month 2026-11     # or --csv path/to/dbip-country-lite-YYYY-MM.csv.gz
php tools/geo-build/test-lookup.php                  # must end "0 failed"
git add geo && git commit -m "Refresh IP to country data (DB-IP Lite YYYY-MM)"
```

Compare the "Source CSV md5" in `geo/README.md` with the MD5SUM on
https://db-ip.com/db/download/ip-to-country-lite. If a well-known address in `test-lookup.php` changes
country, check it by hand before changing the expectation.

## License and attribution

DB-IP Lite data is CC BY 4.0. DB-IP requires web pages that show results to link back:
`<a href="https://db-ip.com">IP Geolocation by DB-IP</a>`. The homepage has this link in the
Built With Gratitude drawer (DB-IP card). Keep it while the data is used. See docs/THIRD-PARTY-NOTICES.md.

## Why DB-IP Lite (checked 2026-10-06)

Three freely licensed country databases were compared against two kinds of known locations:
RIPE Atlas probes (15,076 connected probes; the host sets the probe's country, so these are real
homes and offices) and the per-region address lists that AWS and Google Cloud publish.

| Source (license) | RIPE Atlas IPv4 | RIPE Atlas IPv6 | AWS + Google Cloud |
| --- | --- | --- | --- |
| DB-IP IP to Country Lite 2026-10 (CC BY 4.0) | 97.2% | 81.0% | 94.0% to 100% |
| sapics user-country (PDDL) | 94.7% | 76.7% | 98.4% to 100% |
| iptoasn country (PDDL, registry country) | 86.9% | 70.2% | 16% to 38% |

DB-IP is the most accurate on real visitor networks (what the pill shows), has a clear license with
written attribution terms, and publishes checksums for each monthly release. user-country does better on
cloud ranges (it reads the clouds' own location feeds), but visitors are rarely cloud servers. Registry
data (iptoasn, RIR delegated files) gives the country where a block was registered, not where it is used.

Size: IPv6 is kept at /64 granularity (8-byte keys), which makes the file 5.65 MB instead of about 8.5 MB
with full 16-byte keys. Only 744 of 348,712 DB-IP IPv6 rows have an edge inside a /64; those 358 shared
/64s go to the country that covers most of each.
