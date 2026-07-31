# GAEB XML Parser — Design

Date: 2026-07-31
Status: Approved

## Goal

A small PHP library that parses GAEB DA XML 3.3 files (exchange phases X81–X86)
into a typed, readonly PHP object graph. Read-only, lenient, zero runtime
dependencies beyond `ext-dom`.

## Non-Goals (explicitly out of scope)

- Writing/generating GAEB files
- XSD validation
- Legacy formats (GAEB 90, GAEB 2000)
- Structured rich-text model for descriptions (raw XML is exposed instead)
- Modeling sub-descriptions, surcharge linkage, or bidder text gaps beyond
  what plain text + raw XML access covers

All can be added later without breaking the public API.

## Package & Tooling

- Name: `bambamboole/gaeb-parser`, MIT, PHP `^8.3`
- Runtime deps: none (`ext-dom` only)
- Dev tooling mirrored from `extended-faker`: Pest, PHPStan (level 5),
  Pint (laravel preset + `declare_strict_types`), same composer scripts
  (`test`, `analyse`, `lint`, `test:lint`)
- PSR-4: `Bambamboole\GaebParser\` → `src/`

## Public API

```php
$gaeb = GaebParser::fromFile('tender.x83');   // or ::fromString($xml)
// both static methods return a GaebFile

$gaeb->info;      // GaebInfo
$gaeb->project;   // ProjectInfo
$gaeb->boq;       // ?BoQ

foreach ($gaeb->boq->allItems() as $item) { ... }   // lazy, flattened
```

## Data Model

All DTOs are `final readonly` classes with public properties. No interfaces,
no factories.

- **GaebFile** — `info`, `project`, `boq`
- **GaebInfo** — GAEB version, exchange phase (int 81–86), date, generating
  program name/version
- **ProjectInfo** — project name, label, description, currency
- **BoQ** — BoQ info (label, currency, totals if present), list of top-level
  `BoQCategory`/`Item` nodes, `allItems(): \Generator` yielding every `Item`
  depth-first with its full position number resolved
- **BoQCategory** — partial number (`rNoPart`), label, nested categories,
  items
- **Item** — full position number (`rNo`, e.g. `01.02.0030`), `rNoPart`,
  quantity (`float|null`), unit, short text, long text (flattened plain
  text), `descriptionXml` (raw inner XML of the Description element),
  unit price and total price (`float|null`, populated in X84/X86 files),
  common flags as nullable properties where cheap (e.g. lump-sum marker)

Phase differences are represented purely as nullable properties — one model
for all X8x phases.

## Parsing

- One `GaebParser` class using `DOMDocument`
- Namespace handling: match elements by local name, so the per-phase
  namespaces (`http://www.gaeb.de/GAEB_DA_XML/DA83/3.3`, `…/DA84/3.3`, …)
  and minor version suffixes are handled in one place
- Lenient: missing optional elements produce `null`/empty values
- Number parsing: GAEB uses `.` decimal separator per spec; parse with
  standard float conversion

## Error Handling

- `GaebParseException` thrown for: unreadable file, invalid XML, missing
  `<GAEB>` root element
- Everything else parses leniently

## Testing

- Pest tests against fixtures in `tests/fixtures/`
- Fixtures: publicly available GAEB 3.3 sample files (license-checked
  before inclusion) covering at least one unpriced (X81/X83) and one priced
  (X84) file, plus one small hand-crafted XML covering edge cases (missing
  optionals, nested categories, malformed input for exception paths)
- Assertions: file/project metadata, category tree shape, item counts,
  position numbers, quantities, units, texts, prices, exception behavior
