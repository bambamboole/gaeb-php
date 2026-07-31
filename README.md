# gaeb-parser

A small PHP library that parses [GAEB DA XML](https://www.gaeb.de/) 3.3 files
(exchange phases X81–X86) into a typed, readonly PHP object graph. Read-only
and lenient — missing optional elements simply become `null` instead of
throwing.

## Install

```bash
composer require bambamboole/gaeb-parser
```

Requires PHP `^8.4` and the `ext-dom` extension (usually bundled). No other
runtime dependencies.

## Usage

```php
$gaeb = GaebParser::fromFile('tender.x83');   // or ::fromString($xml)
// both static methods return a GaebFile

$gaeb->info;      // GaebInfo
$gaeb->project;   // ProjectInfo
$gaeb->boq;       // ?BoQ

foreach ($gaeb->boq->allItems() as $item) { ... }   // lazy, flattened
```

`$gaeb->info` exposes the GAEB version, exchange phase (`81`–`86`), date, and
generating program. `$gaeb->project` exposes the project name, label, and
currency. `$gaeb->boq` is `null` when the file has no bill of quantities;
otherwise it holds the BoQ label/currency/totals plus the top-level
category/item tree, and `allItems()` lazily yields every `Item` depth-first
with its full position number (`rNo`, e.g. `01.02.0030`) resolved. An item's
`descriptionXml` holds the raw XML (the serialized `Description` element).

## Item classification & totals

Each `Item` also carries: `provisional` (`Provisional::WithoutTotal|WithTotal`
— a Bedarfsposition, `null` when the item isn't one), `hourlyWork` (Stundenlohnarbeiten),
`notApplicable` (a dropped/void position), and `alternativeGroupNo`/`alternativeSerialNo`
(the `ALNGroupNo`/`ALNSerNo` pair linking a base position to its alternatives).
Sum semantics are the caller's responsibility: for an alternative group only the
awarded serial number counts, `Provisional::WithoutTotal` and `notApplicable`
items are excluded from BoQ totals, and `hourlyWork` items are typically listed
separately from the main sum.

`$gaeb->boq->totals` (`?Totals`) holds the full breakdown when present: `total`,
`discountPercent`, `discountAmount`, `totalAfterDiscount`, `vat`, `vatAmount`,
`totalNet`, `totalGross`.

## Bid data

Each `Item` also carries three bid-related fields. `textComplements`
(`list<TextComplement>`) are the Bieterlücken — gaps embedded in the short/long
text that either the tendering office fills in (`TextComplementKind::Owner`)
or the bidder must fill in (`TextComplementKind::Bidder`). `bidderComment` is
every `BidComm` on the item flattened and joined with `"\n"` (`null` when
absent). `subDescriptions` (`list<SubDescription>`) are the item's
`SubDescr` children, each with its own `subDNo`, `shortText`/`longText`/
`descriptionXml`, `qty`, `unit`, and `unitPrice`.

Find the gaps a bidder still needs to fill:

```php
$gaps = array_filter($item->textComplements, fn ($c) => $c->kind === TextComplementKind::Bidder);
```

## Custom drivers / instance API

`GaebParser::fromFile()`/`::fromString()` are shortcuts for `new GaebParser`.
Use the instance API directly to inject your own driver(s):

```php
$parser = new GaebParser([new MyDriver, new GaebXmlDriver]);
$gaeb = $parser->parse($content);   // or ->parseFile($path)
```

Drivers are tried in order; the first one whose `supports(string $content): bool`
returns `true` handles the parse. Implement `Bambamboole\GaebParser\Driver\Driver`
to add support for another format without touching this library.

## Out of scope

- Writing/generating GAEB files
- XSD validation
- Legacy formats (GAEB 90, GAEB 2000)
- Markup/surcharge items (`MarkupItem`) — skipped silently

These may be added later without breaking the public API.

## Testing

All fixtures under `tests/fixtures/` are synthetic, hand-crafted XML files
written for this project to exercise specific parsing paths — see
`tests/fixtures/README.md`.

## License

MIT — see [LICENSE.md](LICENSE.md).
