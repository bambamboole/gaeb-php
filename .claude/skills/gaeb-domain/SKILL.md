---
name: gaeb-domain
description: GAEB format reference for this package. Use when changing parsing logic, authoring or editing test fixtures, adding a format driver, debugging why a GAEB file parses unexpectedly, or answering questions about GAEB structure, phases, or element names.
---

# GAEB Domain Knowledge

GAEB (Gemeinsamer Ausschuss Elektronik im Bauwesen) defines the German
standard for exchanging construction-tender data: bills of quantities
(Leistungsverzeichnisse), bids, and awards.

## Format landscape

| Format | Serialization | Extensions | Status here |
|---|---|---|---|
| GAEB 90 | fixed-column line records; every line starts with a 2-digit record type (Satzart) | `.d81`–`.d86` | detected, rejected with a clear error |
| GAEB 2000 | tagged text blocks `#begin[...]` / `#end[...]` | `.p81`–`.p86`, `.d81`… | detected, rejected with a clear error |
| GAEB DA XML 3.x | XML; namespace varies per phase and version: `http://www.gaeb.de/GAEB_DA_XML/DA<phase>/<version>` | `.x81`–`.x86` | fully supported (parser is version-lenient via local-name matching) |

Adding a format = one new class implementing
`Bambamboole\GaebParser\Driver\Driver` (`supports()` + `parse()` →
`GaebFile`), added to `GaebParser`'s default driver list. Map into the
existing DTO graph; never invent a parallel model.

## Exchange phases

| DP | German name | Meaning | Prices populated? |
|---|---|---|---|
| 81 | LV-Übergabe | BoQ handover | no |
| 82 | Kostenanschlag | cost estimate | partly |
| 83 | Angebotsaufforderung | request for tender | no |
| 84 | Angebotsabgabe | bid submission | yes (`UP`, `IT`, `Totals`) |
| 85 | Nebenangebot | side bid | yes |
| 86 | Zuschlag / Auftrag | award / contract | yes |

Phase detection: `Award/DP` element when numeric, else the `DA(8x)`
segment of the root namespace.

## XML anatomy (elements this package parses)

```
GAEB
├── GAEBInfo            Version, Date, ProgSystem | ProgName
├── PrjInfo             Name | NamePrj, LblPrj, Cur
└── Award
    ├── DP              exchange phase (81–86)
    ├── AwardInfo       Cur (currency fallback)
    └── BoQ
        ├── BoQInfo     LblBoQ | Name, Cur, Totals/Total
        └── BoQBody
            ├── BoQCtgy (RNoPart, LblTx) → nests via its own BoQBody
            └── Itemlist
                └── Item (RNoPart, RNoIndex?)
                    ├── Qty, QU, UP, IT, LumpSumItem
                    └── Description/CompleteText
                        ├── OutlineText/OutlTxt/TextOutlTxt   (short text)
                        └── DetailTxt/Text                    (long text)
```

## Real-world element variants (CRITICAL)

Files from real AVA tools deviate from a naive reading of the spec. The
parser reads BOTH spellings — never remove a fallback:

| Data | Primary | Also seen in the wild |
|---|---|---|
| project name | `PrjInfo/Name` | `PrjInfo/NamePrj` (BVBS certification file) |
| currency | `PrjInfo/Cur`, `BoQInfo/Cur` | `Award/AwardInfo/Cur` |
| BoQ label | `BoQInfo/LblBoQ` | `BoQInfo/Name` |
| generator | `GAEBInfo/ProgSystem` | `GAEBInfo/ProgName` |

More wild-file traps:

- Items can carry `RNoIndex` next to `RNoPart`
  (`RNoPart="0010" RNoIndex="1"` → rNo segment `0010.1`). Ignoring it
  produces duplicate position numbers.
- Items may have NO `Description` element at all — must parse with all
  texts `null`.
- `MarkupItem` (surcharge items) exist in real files and are silently
  skipped (documented as out of scope in the README).

## Position numbers (rNo)

`rNo` = the `RNoPart` of every ancestor `BoQCtgy` plus the item's own
segment, dot-joined. The item segment is `RNoPart` or
`RNoPart.RNoIndex`. Example: categories `01` → `02`, item `0010` index
`1` → `01.02.0010.1`. `Item->rNoPart` stays the bare part.

## Text markup

Short and long texts contain XHTML-ish `<p><span>` runs. Flattening
rule: paragraphs joined with `"\n"`, inline content via `textContent`,
empty paragraphs dropped. The raw serialized `<Description>` element is
preserved in `Item->descriptionXml` for consumers needing formatting.

## Fixture authoring rules

- Synthetic and self-authored only. NEVER commit third-party GAEB files
  unless redistribution rights come from the copyright holder (an
  MIT-licensed repo containing someone else's file grants nothing).
- Use realistic element names, including the wild variants above.
- Set the namespace to match the phase, e.g.
  `http://www.gaeb.de/GAEB_DA_XML/DA84/3.3` with `<DP>84</DP>`.
