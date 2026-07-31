# gaeb-parser

A small PHP library that parses [GAEB DA XML](https://www.gaeb.de/) 3.3 files
(exchange phases X81–X86) into a typed, readonly PHP object graph. Read-only
and lenient — missing optional elements simply become `null` instead of
throwing.

## Install

```bash
composer require bambamboole/gaeb-parser
```

Requires PHP `^8.3` and the `ext-dom` extension (usually bundled). No other
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
generating program. `$gaeb->project` exposes the project name, label,
description, and currency. `$gaeb->boq` is `null` when the file has no bill
of quantities; otherwise it holds the BoQ label/currency/totals plus the
top-level category/item tree, and `allItems()` lazily yields every `Item`
depth-first with its full position number (`rNo`, e.g. `01.02.0030`)
resolved.

## Out of scope

- Writing/generating GAEB files
- XSD validation
- Legacy formats (GAEB 90, GAEB 2000)

These may be added later without breaking the public API.

## Testing

Most fixtures under `tests/fixtures/` are synthetic, hand-crafted XML files
written to exercise specific parsing paths. `tests/fixtures/sample.x84` is a
real-world GAEB sample file — see `tests/fixtures/README.md` for its origin
and license.

## License

MIT — see [LICENSE.md](LICENSE.md).
