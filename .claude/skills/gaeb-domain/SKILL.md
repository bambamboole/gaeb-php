---
name: gaeb-domain
description: GAEB format reference for this package. Use when changing parsing logic, writing X84 bids, authoring or editing test fixtures, adding a format driver, debugging why a GAEB file parses unexpectedly, or answering questions about GAEB structure, phases, or element names.
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
├── GAEBInfo            Version, VersDate, Date, ProgSystem | ProgName
├── PrjInfo             NamePrj (Name is a lenient fallback only), LblPrj, Cur
└── Award
    ├── DP              exchange phase (81–86)
    ├── AwardInfo       Cur (currency fallback)
    └── BoQ
        ├── BoQInfo     Name, LblBoQ, Cur, Totals/Total
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

The parser reads BOTH spellings for several fields — never remove a
fallback. The table below states the schema-verified primary (checked with
xmllint against the official GAEB 3.3 XSDs) versus the lenient fallback the
parser also accepts:

| Data | Schema-primary | Lenient fallback |
|---|---|---|
| project name | `PrjInfo/NamePrj` | `PrjInfo/Name` (NOT in the schema — no `Name` element exists under `PrjInfo`; kept only for real-world files that use it anyway) |
| currency | `PrjInfo/Cur`, `BoQInfo/Cur` (X81–83 only; X84's restricted `BoQInfo` has no `Cur` at all) | `Award/AwardInfo/Cur` |
| BoQ label | `BoQInfo/Name` (required, max 20 chars) then `BoQInfo/LblBoQ` (also legitimate, comes after `Name`) | — both are schema-valid; the parser prefers `LblBoQ` when present |
| generator | `GAEBInfo/ProgSystem` | `GAEBInfo/ProgName` |

`BoQInfo` legitimately contains BOTH `Name` (first, required) and `LblBoQ`
(after it) — `Name` is not a "wild" variant, it's the schema-required
identifier; `LblBoQ` is the human-readable label. `PrjInfo/Name` is the one
true wild-file trap: it has no schema basis whatsoever, so `NamePrj` must
always be the schema-primary read.

## Other schema facts (verified with xmllint)

- `GAEBInfo` requires `VersDate` right after `Version` (full required
  order: `Version, VersDate, Date`; `Time`, `ProgSystem`, `ProgName` stay
  optional).
- `BoQ`, `BoQCtgy`, and `Item` all require an `ID` attribute (`xs:ID`,
  unique across the whole document).
- X84 (`Award`) requires `AwardInfo` and `CTR` (contractor block, needs at
  least `Address/Name1|Street|PCode|City`) before `BoQ`.
- X84's restricted `BoQInfo` drops `LblBoQ`, `Cur`, and `CPVCode`
  entirely — only `Name` (≤20 chars, required), `BoQBkdn` (required),
  `Totals`, `QtyDetermInfo` remain. X84's restricted `BoQCtgy` drops
  `LblTx` and requires `Totals`. X84's restricted `Item` drops `QU` and
  `LumpSumItem` entirely — those exist only in X81–83.
- X84 item `Description/CompleteText` allows only `DetailTxt`, no
  `OutlineText` — short text (`TextOutlTxt`) is an X81–83-only concept.

More wild-file traps:

- Items can carry `RNoIndex` next to `RNoPart`
  (`RNoPart="0010" RNoIndex="1"` → rNo segment `0010.1`). Ignoring it
  produces duplicate position numbers.
- Items may have NO `Description` element at all — must parse with all
  texts `null`.
- `MarkupItem` (surcharge items) exist in real files and are silently
  skipped (documented as out of scope in the README).

## Text complements (Bieterlücken)

`TextComplement` elements (`tgTextComplement` in the Lib XSD) are gaps
embedded directly inside an item's rich text, authored by one side and
filled in by the other. They live as siblings of the running text, not
wrapped inside it:

- Short text: inside `OutlTxt`, alongside `TextOutlTxt` (`Lib` ~2196).
- Long text: inside `DetailTxt`, alongside `Text` (`Lib` ~3180).

Attributes: `MarkLbl` (integer, the marker referenced from the surrounding
prose), `Kind` (`Owner` — the tendering office authored/filled this, or
`Bidder` — the bidder must fill this). Children: `ComplCaption` (text before
the gap), `ComplBody` (the gap's content — empty/self-closing when unfilled),
`ComplTail` (text after the gap). `ComplTSA`/`ComplTSB` are redundant
summary flags ("this text has owner/bidder complements") mirroring what a
consumer can already derive from the parsed list — skipped.

The parser flattens `DetailTxt` as a whole for `Item->longText`, so a
complement's caption/body/tail paragraphs end up concatenated into
`longText` alongside the plain `Text` runs, in document order. Consumers who
want the two concerns apart use `textComplements` for the gaps and treat
`longText` as the merged prose.

### Phase-restriction findings (verified with xmllint against the 3.3
2021-05 phase XSDs, `tests/fixtures/boq.x83` and `realistic.x84` iterated
until clean)

- **X83** keeps `tgTextComplement` essentially unrestricted: `ComplCaption`,
  the `ComplBodyDec`/`ComplBodyInt` choice, `ComplBody`, `ComplTail` all
  survive, `Kind` still accepts both `Owner` and `Bidder`. X83's `tgItem`
  has **no `BidComm` element at all** — bidder comments only exist on the
  X84 (bid-submission) side. X83's `tgSubDescr` has no `UP` — only the
  `UPSpec`/`UPBkdn` yes/no flags — so `SubDescription->unitPrice` can only
  ever be populated from an X84 (or later) fixture.
- **X84** restricts `tgTextComplement` to drop `ComplCaption` and
  `ComplTail` entirely — only the `ComplBodyDec`/`ComplBodyInt` choice and
  `ComplBody` remain. A filled X84 gap therefore always has `caption` and
  `tail` as `null`, only `body` set. X84's `tgItem` does carry `BidComm`
  (unbounded) and `SubDescr` (with a real `UP`, unlike X83). X84's
  restricted `tgSubDescr` also drops `QU` entirely — a sub-description's
  `unit` stays `null` on the bid-submission side; the parent item's own
  `QU` already fixes the unit for the whole item.
- **X84 surprise**: `tgpTC` (the `<p>` type used inside `Text`/`DetailTxt`
  runs) is restricted to a choice of **only `TextComplement`** — `span`,
  `br`, and `image` are all dropped. In other words, X84 bid items cannot
  carry new narrative long text at all (`<Text><p><span>…` is
  schema-invalid there); a bid submission can only reference the LV's
  existing long text and fill in `TextComplement` gaps against it. This
  applies to `SubDescr/Description` too, which is why `realistic.x84`'s
  priced sub-description omits `Description` entirely rather than trying
  to give it a long text.

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

## Writing X84 bids

`GaebDocument::createBid(Bid $bid)` builds a **new** X84 DOM by walking the
source X81/X83 DOM (`src/Write/BidWriter.php`, `@internal`) — derived from
source, never mutating it: `ID` and `RNoPart` are copied verbatim from the
source; `Qty` is re-emitted, normalized to 3 decimals
(`number_format($qty, 3, '.', '')`), not copied verbatim. The `Bid` only
supplies what changed: `UP`, filled `TextComplement`s, `BidComm`.

Schema findings from the X84 + Lib XSDs (verified with xmllint, re-confirmed
against `priced.x84`/`realistic.x84` while implementing the writer):

- **`GAEBInfo/Date` is required**, in required order `Version, VersDate,
  Date` — X84 does not restrict `tgGAEBInfo`, so the Lib type's requirement
  applies unchanged (an earlier assumption that `Date` could be omitted for
  determinism was wrong). `BidWriter` emits `$bid->date ?? date('Y-m-d')` —
  pass `Bid`'s third constructor param for byte-deterministic output.
- **`BoQCtgy/Totals` is required, not optional**, on every category
  including nested ones — each is a recursive rollup of that category's own
  subtree, computed under the same inclusion rules as the document total.
- **`TextComplement` nests directly under `DetailTxt`** — `tgBoQText`
  allows a repeated choice of `Text`/`TextComplement` as direct children, so
  gap fills need no `<Text><p>` wrapper: `BidWriter::buildDescription()`
  emits `DetailTxt > TextComplement(MarkLbl, Kind=Bidder) > ComplBody > p >
  span` straight away.
- **`BoQInfo/BoQBkdn` is required** (`minOccurs` defaults to 1,
  `maxOccurs="7"`) — copied verbatim from the source and re-namespaced into
  DA84 via `BidWriter::reNamespace()` (a generic deep-clone-into-new-
  namespace helper; a plain `importNode` would keep the source's DA83
  namespace and fail validation).

`CTR/Address` (`tgAddress`, unrestricted by X84): required `Name1`,
`Street`, `PCode`, `City`, in that order, then optional fields including
`Phone` before `Email`. `Dto\Contractor` mirrors this — its six properties
are all nullable, but `BidWriter::buildCTR()` throws `GaebWriteException`
naming the missing field(s) if `name`/`street`/`zip`/`city` isn't fully set.

Emission gotchas:

- Priceable = every item except `notApplicable` ones; those are omitted
  from the output entirely and need no price. A missing price on any other
  item throws `GaebWriteException` listing every offending `rNo` — writing
  never emits a silent `UP 0.000`.
- An empty `BoQCtgy` (every item under it `notApplicable`) is dropped
  entirely rather than emitted with a hollow `Totals/Total 0.00`; if the
  whole bid ends up empty this way, `createBid` throws
  `GaebWriteException('Bid contains no items')` — `tgBoQ` requires
  `BoQBody`.
- `IT` = `round(qty × UP, 2)`, or `round(UP, 2)` for qty-less (lump-sum)
  items; `UP` is formatted `number_format($v, 3, '.', '')` (`tgDecimal_13_3`).
- Totals inclusion mirrors the read-side classification: excludes
  `Provisional::WithoutTotal` items and non-base alternatives
  (`alternativeGroupNo` set with `alternativeSerialNo !== 1`); includes
  `hourlyWork` items.

## Official XSDs

The official GAEB 3.3 XSD set is committed UNMODIFIED under
`docs/gaeb/3.3/` (versioned per GAEB release). Provenance, source URL and
copyright attribution live in `docs/gaeb/README.md` — the schemas are
GAEB/DIN works redistributed byte-identically; NEVER modify them, and
never commit the Fachdokumentation PDF (© DIN, git-ignored).
`tests/SchemaValidationTest.php` validates the four standard fixtures
(`minimal.x83`, `boq.x83`, `priced.x84`, `realistic.x84`) against
`docs/gaeb/3.3/2021-05_Leistungsverzeichnis/` with
`DOMDocument::schemaValidate()`; tests skip when the directory is absent.
Point `GAEB_XSD_DIR` at a different location to override. `tests/fixtures/nonconforming.x83` is intentionally
schema-INVALID — it exists to exercise the parser's leniency fallbacks
(`tests/LenientParsingTest.php`) and is deliberately excluded from
`SchemaValidationTest`.
