# GAEB DA XML schema files

The `3.3/` directory contains the official GAEB DA XML 3.3 schema files
(XSD), published by the Gemeinsamer Ausschuss Elektronik im Bauwesen
(GAEB). They are included here **unmodified, byte-for-byte** as downloaded,
solely so that this library's test suite can validate its fixtures against
the official schemas (see `tests/SchemaValidationTest.php`).

- Source: <https://www.gaeb.de/service/downloads/gaeb-datenaustausch/>
  (publicly available, no registration required)
- Downloaded: 2026-07-31
- Copyright: the schemas are the work of GAEB / DIN Deutsches Institut
  für Normung e. V. All rights remain with the rights holders. Their
  inclusion here is not a claim of ownership and does not place them
  under this repository's MIT license.
- Per GAEB's guidance, the schema files must not be modified — and none
  are.

If you represent GAEB or DIN and want these files removed from this
repository, open an issue and they will be removed immediately.

The accompanying GAEB Fachdokumentation (PDF, © DIN) is deliberately NOT
committed — it is git-ignored. Download it from the source above if you
need it.
