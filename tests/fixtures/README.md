# Test fixtures

All fixtures in this directory are synthetic, hand-crafted XML files written
for this project to exercise specific parsing paths. None of them are
copies of, or derived from, third-party GAEB sample/certification files.

`realistic.x84` is modeled on the structure and element naming of a
real-world GAEB DA XML 3.3 "Bauausfuehrung" (X84) file — including
`NamePrj`, `Award/AwardInfo/Cur`, `BoQInfo/Name`, `GAEBInfo/ProgName`,
`RNoIndex` item variants, and an item with no `Description` element — but
all project names, texts, and numbers are invented for this test suite.
