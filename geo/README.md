# geo/ (CHG-076)

Bundled IP to country database for `api/geo-country.php` (the visitor country pill in Live-state).
The web server denies HTTP access to this folder (`geo/.htaccess`); PHP reads the files from disk.
Visitor addresses are only compared against `ip-country.bin` on this server. They are never sent to
another service, logged, or stored.

| File | What |
| --- | --- |
| `ip-country.bin` | packed ranges, format in `tools/geo-build/build.py` and `geo/lookup.php` |
| `lookup.php` | `af_geo_lookup()` binary search (no network, a few small file reads) |
| `country-names.php` | code to name map, generated from `tools/geo-build/country-names.tsv` |
| `visited.php` | CHG-077: the 249 ISO 3166-1 alpha-2 codes and the countries-seen set in `data/countries-visited.json` (codes only; not generated) |

## Source

- Data: DB-IP IP to Country Lite, release 2026-10 (`dbip-country-lite-2026-10.csv.gz`)
- Download page: https://db-ip.com/db/download/ip-to-country-lite
- Source file sha256 (as downloaded): `097426b8ddae89157d444a59ac1847e873f7943c32d52becc4371c8b0273af80`
- Source CSV md5 (compare with the MD5SUM on the download page): `4c77817b3a3397de2b871257670f9687`
- License: Creative Commons Attribution 4.0 International (CC BY 4.0), https://creativecommons.org/licenses/by/4.0/
- Attribution (required): pages that show results link back to DB-IP. The homepage has
  `<a href="https://db-ip.com">IP Geolocation by DB-IP</a>` in the Built With Gratitude drawer.
- Changes made to the data: any gaps filled as unknown, IPv6 reduced to /64 units (358 shared /64s go
  to the largest share), special-purpose ranges forced to unknown, neighboring ranges with the same
  country merged, repacked as binary.

## Build

- Built 2026-10-06 by `tools/geo-build/build.py`
- Source rows: 710834; IPv4 ranges: 362122; IPv6 /64 ranges: 348105; countries used: 250
- `ip-country.bin`: 5653846 bytes, sha256 `cb988aadd04fc3184cf5ff95f56a7dfa36f57cb7378ed2e17b3aae32385d5a5d`

Refresh (DB-IP publishes a new Lite release at the start of each month):
`python3 tools/geo-build/build.py --month YYYY-MM && php tools/geo-build/test-lookup.php`, then commit
`geo/` in one change. Each refresh adds roughly the compressed size of `ip-country.bin` to git history,
so refreshing every few months is enough for a country pill.
