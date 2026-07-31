# gaeb-parser

A small PHP library that parses [GAEB DA XML](https://www.gaeb.de/) 3.3 files
(exchange phases X81–X86) into a typed, readonly PHP object graph, and writes
schema-valid X84 bids back out. Reading is lenient — missing optional
elements simply become `null` instead of throwing. Writing is strict — see
["Writing a bid (X84)"](#writing-a-bid-x84) below.

## Install

```bash
composer require bambamboole/gaeb-parser
```

Requires PHP `^8.4` and the `ext-dom` extension (usually bundled) plus
`brick/math` for decimal-exact money handling in the write path. No other
runtime dependencies.
Built on PHP 8.4's native `Dom\XMLDocument` API for lightweight XML processing.

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
`descriptionXml` holds the raw XML (the serialized `Description` element,
self-contained with its own `xmlns="…"` declaration).

X84/X86 files also expose the parties and award data:

```php
$gaeb->owner;      // ?Party — client (Auftraggeber, OWN)
$gaeb->contractor; // ?Party — contractor/bidder (Auftragnehmer, CTR)
$gaeb->award;      // ?AwardData — populated from AwardInfo on any phase; meaningfully filled (dates, duration) on X86
```

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
$gaps = array_values(array_filter($item->textComplements, fn ($c) => $c->kind === TextComplementKind::Bidder));
```

## Writing a bid (X84)

`GaebDocument` opens a received tender (X81/X83) and turns it into a new,
schema-valid X84 bid — the source document is never mutated. Collect the
bid's prices, filled bidder text gaps, and comments on a `Bid` builder, then
transform:

```php
use Bambamboole\GaebParser\GaebDocument;
use Bambamboole\GaebParser\Write\Bid;
use Bambamboole\GaebParser\Dto\Contractor;

$tender = GaebDocument::open('tender.x83');

$contractor = new Contractor(
    name: 'ACME Bau GmbH',
    street: 'Musterstraße 1',
    zip: '12345',
    city: 'Berlin',
    email: 'info@acme.example',
    phone: '+49 30 1234567',
);

$bid = new Bid($contractor, currency: 'EUR', date: '2026-07-31');
// currency defaults to the source's currency, date defaults to today —
// pass both explicitly for a byte-deterministic output.

$bid->setUnitPrice('01.02.0010', '12.50')   // decimal strings are exact, floats convenient
    ->fillGap('01.02.0010', 1, 'Musterhersteller GmbH')
    ->setComment('01.02.0010', 'Lieferzeit 4 Wochen');
// ...one setUnitPrice() per priceable item (every item that isn't marked
// notApplicable) — createBid() throws GaebWriteException naming any that's
// missing a price, or any rNo it doesn't recognize.

$award = $tender->createBid($bid);

$errors = $award->validate();   // [] means schema-valid
if ($errors !== []) {
    throw new RuntimeException(implode("\n", $errors));
}

file_put_contents('bid.x84', (string) $award);
```

The computed `BoQ` total sums each emitted item's `IT`, excluding
`Provisional::WithoutTotal` items and non-base alternatives
(`alternativeGroupNo` set with `alternativeSerialNo !== 1`); `notApplicable`
items are never emitted at all. Reading tolerates schema deviations and
wild-file spellings; writing refuses to guess — anything that would corrupt
or invalidate the bid (a missing price, an unknown `rNo`) throws
`GaebWriteException` instead of silently producing a bad file.

`GaebDocument::validate(?string $xsdDir = null): array` schema-checks the
document against the XSDs bundled in the package
(`docs/gaeb/3.3/2021-05_Leistungsverzeichnis/`, resolved by the document's
phase); pass `$xsdDir` to validate against a different XSD set instead. An
empty array means valid; otherwise it's the flattened list of libxml error
strings.

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

- Writing formats other than the X84 bid transform above: from-scratch
  X81/X83 authoring, X86/X89 award/rejection writing
- `NotOffered` marking, per-item `VAT`, `UPComp1–6` price components,
  sub-description prices, `TimeQu`, `Product` when writing bids
- Legacy formats (GAEB 90, GAEB 2000)
- Markup/surcharge items (`MarkupItem`) — skipped silently on read

These may be added later without breaking the public API.

## Testing

All fixtures under `tests/fixtures/` are synthetic, hand-crafted XML files
written for this project to exercise specific parsing paths — see
`tests/fixtures/README.md`.

## License

MIT — see [LICENSE.md](LICENSE.md).
