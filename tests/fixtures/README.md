# Test fixtures

All fixtures in this directory are synthetic, hand-crafted XML files written
for this project to exercise specific parsing paths. None of them are
copies of, or derived from, third-party GAEB sample/certification files.

`realistic.x84` is modeled on the structure and element naming of a
real-world GAEB DA XML 3.3 "Bauausfuehrung" (X84) file — including
`NamePrj`, `Award/AwardInfo/Cur`, `BoQInfo/Name`, `GAEBInfo/ProgName`,
`RNoIndex` item variants, and an item with no `Description` element — but
all project names, texts, and numbers are invented for this test suite.

`contract.x86` is a synthetic X86 award/contract file — `OWN`/`CTR`
parties, `AwardInfo` contract/bid/construction/warranty data, and the
awarded BoQ, all self-authored and schema-valid.

## Schema validity

`minimal.x83`, `boq.x83`, `priced.x84`, `realistic.x84`, and
`contract.x86` are valid against the official GAEB 3.3 XSDs
(`GAEB_DA_XML_83_3.3_2021-05.xsd` / `GAEB_DA_XML_84_3.3_2021-05.xsd` /
`GAEB_DA_XML_86_3.3_2021-05.xsd`). `tests/fixtures/nonconforming.x83` is
**intentionally invalid** — it drops required elements/attributes (no
`VersDate`, `PrjInfo/Name` instead of `NamePrj`, `BoQInfo/LblBoQ` without
`Name`, no `ID` attributes, an item without `Description`) to exercise the
parser's leniency fallbacks in `tests/LenientParsingTest.php`. Never make
it schema-valid.

To validate: the official GAEB 3.3 XSD set is committed unmodified under
`docs/gaeb/3.3/` (provenance in `docs/gaeb/README.md`); point
`GAEB_XSD_DIR` elsewhere to override, then run:

```
vendor/bin/pest tests/SchemaValidationTest.php
```

The test skips entirely when no XSD directory is found; with the XSDs
committed, it runs in CI as well.
