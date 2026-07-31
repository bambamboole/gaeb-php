# gaeb-php

Small PHP library (runtime deps: ext-dom + brick/math only) parsing GAEB DA XML files (German
construction-tender exchange format) into readonly PHP objects. Read-only
and lenient. Supports GAEB DA XML 3.x (phases 81–86); GAEB 90 and
GAEB 2000 are detected but rejected with a clear error — the driver seam
exists so they can be added later.

## Hard invariants (never break without approval)

- **Runtime dependencies: `ext-dom` and `brick/math` ONLY.** Never add
  another composer runtime dependency without approval.
- **Lenient parsing contract:** `GaebParseException` is thrown ONLY for
  unreadable files, unparseable/unrecognized input, or a missing `<GAEB>`
  root. Every missing or optional element yields `null` (or an empty
  list) — never throw over content.
- **Strict write contract:** `GaebWriteException` is thrown for missing
  unit prices on priceable items, unknown `rNo`s referenced in a `Bid`, a
  bid that would end up with zero items, or missing required
  contractor/source fields — writing never silently drops, defaults, or
  guesses data. The read path stays unchanged and lenient; this is a
  write-only addition.
- **DTOs** (`src/Dto/`) are `final readonly` with public promoted
  properties. No interfaces, factories, or setters in the DTO layer.
- **Element lookup by LOCAL NAME only** — never match namespaces or
  prefixes; the GAEB namespace varies per exchange phase and version.
- Every PHP file starts with `<?php declare(strict_types=1);`.
- PHP `^8.4` (8.4 and 8.5).
- The public API (`GaebParser::fromFile()/fromString()`, instance
  `parse()/parseFile()`, the DTO property names) is a BC surface —
  changes need approval.

## Architecture

- `GaebParser` is a thin facade: it asks each `Driver` (`src/Driver/`)
  via `supports()` and delegates to the first match; unmatched content is
  format-sniffed to produce a helpful error (GAEB 90 / GAEB 2000 /
  unrecognized).
- All XML parsing lives in `GaebXmlDriver`. A new format means a new
  class implementing `Bambamboole\Gaeb\Driver\Driver` that maps
  into the existing DTO graph — never bolt format branches into the
  facade, never invent a parallel object model.
- DTO graph: `GaebFile → GaebInfo / ProjectInfo / BoQ → BoQCategory /
  Item`, plus nullable award sections `owner`/`contractor` (`Party`, from
  `Award/OWN`/`CTR`) and `award` (`AwardData`, from `Award/AwardInfo`).
  Phase differences are nullable properties; one model for all phases.
- `GaebDocument` is the read/write handle: `open()`/`fromString()` load a
  DOM; `file()`/`phase()` lazily parse it into a `GaebFile` via
  `GaebXmlDriver` (cached); `validate(?string $xsdDir = null)`
  schema-checks against the bundled XSDs; `toString()` emits (also via
  `Stringable`/`(string)`; `jsonSerialize()` exposes the parsed `GaebFile`);
  `createBid(Bid $bid)` runs the X81/X83 → X84 transform and returns a new
  `GaebDocument` — the source is never mutated. Persistence (writing to
  disk) is the caller's concern, not this class's. `createInvoice(Invoice
  $invoice)` runs the X86 → X89 transform the same way (strict, cumulative
  quantities, exact money); `createOrderConfirmation()` re-stamps an X86 as
  an X87 (DA87 namespace, DP 87, fresh GAEBInfo) via `Dom::cloneInto`;
  `validate()` resolves XSDs per phase family
  (89 → `2021-05_Rechnung`, everything else → `2021-05_Leistungsverzeichnis`).
- `src/Write/Bid.php` is the mutable bid builder (prices/gap
  fills/comments keyed by `rNo`); `src/Write/BidWriter.php` (`@internal`)
  builds the X84 DOM from the source DOM plus the parsed `GaebFile`; the
  bid's contractor is a `Party` (`src/Dto/Party.php`, the one DTO for the
  spec's single `tgAddress` type). Both writers extend
  `src/Write/Writer.php` (`@internal`), which holds the shared emission
  helpers (`elem`, `reNamespace`, `buildGaebInfo`, `buildPrjInfo`, the
  `BoQBody` walker) parameterized by the phase namespace. Write path is
  strict via `GaebWriteException`; read path is unchanged and lenient.
- `src/Write/Invoice.php` is the analogous mutable invoice builder
  (cumulative billed quantities keyed by `rNo`, prior payments);
  `src/Write/InvoiceWriter.php` (`@internal`) builds the X89 DOM from the
  X86 source DOM plus the parsed `GaebFile`. On read, `GaebFile->invoice`
  (`InvoiceData`) carries the header/parties/payments/totals of an X89
  file.
- `src/Xml/Dom.php` (`@internal`) holds the shared DOM helpers (`child`,
  `children`, `text`, `floatVal`, `intVal`, `flatten`, `hasAncestorP`);
  `GaebXmlDriver` and `BidWriter` both use it — never duplicate
  DOM-walking helpers. XML internals use PHP 8.4's native `Dom\` API
  (`Dom\XMLDocument`); direct-child element lookups via `Xml\Dom::child()`
  (`:scope` selectors unsupported on the native API).
- Money and quantities are decimal-exact via `brick/math` across the WHOLE
  model: every read-model money/qty field is `?BigDecimal` (never float —
  `Dom::decimal()` parses leniently, garbage numeric content yields `null`),
  and the write path computes with `RoundingMode::HalfUp`, scale 3 for unit
  prices, scale 2 for item totals and final sums. A source `Qty` that exists
  but is non-numeric still throws `GaebWriteException` at write time
  (lenient read, strict write). `BigDecimal` JSON-serializes as a string
  (`"45.50"`), so `jsonSerialize()` emits money as strings.

## Verification

- Before any commit: `composer lint && composer analyse && composer test`
  — all green, pristine output (no warnings). Never commit on red.
- CI runs the same gate on PHP 8.4 and 8.5.

## Testing & fixtures

- Pest. Test through the public API; never mock parser internals.
- Fixtures in `tests/fixtures/` are synthetic and self-authored. NEVER
  commit a third-party GAEB file unless the redistribution license chain
  terminates at the copyright holder — an MIT-licensed repo containing
  someone else's file grants nothing.
- The eleven standard fixtures (`description.x80`, `minimal.x83`, `boq.x83`,
  `markup.x83`, `priced.x84`, `components.x84`, `realistic.x84`,
  `contract.x86`, `nachtrag.x86`,
  `confirmation.x87`, `invoice.x89`) must validate against the
  official GAEB 3.3 XSDs when they're available — they span two XSD family
  dirs (`2021-05_Leistungsverzeichnis/` for X80–X87, `2021-05_Rechnung/` for
  X89): `tests/SchemaValidationTest.php` runs `Dom\XMLDocument::schemaValidate()`
  against `docs/gaeb/3.3/` (or `GAEB_XSD_DIR`) and skips cleanly when the
  XSDs aren't present.
  `tests/fixtures/nonconforming.x83` is deliberately schema-invalid and
  covers the parser's leniency fallbacks instead.
- Real-world element variants matter more than naive spec reading: keep
  coverage for `NamePrj` (the schema-primary project name element —
  `PrjInfo/Name` has no schema basis and is a leniency fallback only),
  `Award/AwardInfo/Cur`, `BoQInfo/Name`, `ProgName`, and `RNoIndex` (see
  the gaeb-domain skill).

## Comments

- Code must be self-explanatory: clear names, small functions, and types
  before comments.
- Comments only explain *why*, never *what*. Delete obsolete or "what"
  comments on sight.
- Keep PHPDoc only when it carries type information (array shapes,
  generators) or a non-obvious constraint.

## Git

- Conventional commits (`feat:` `fix:` `chore:` `refactor:` `test:`
  `docs:`), one logical change per commit.
- Never credit the agent: no `Co-Authored-By` and no "generated by"
  attribution in commits or PRs.
- Never commit agent planning artifacts or scratch files
  (`docs/superpowers/`, `.superpowers/` and similar) — both are
  git-ignored on purpose.
- The official GAEB 3.3 XSDs are committed UNMODIFIED under
  `docs/gaeb/3.3/` with provenance in `docs/gaeb/README.md` (they are
  publicly distributed by GAEB without stated restrictions; attribution
  and byte-identical redistribution are the terms we hold ourselves to).
  Never modify them. Never commit the GAEB Fachdokumentation PDF — it is
  explicitly © DIN and stays git-ignored (`docs/gaeb/*.pdf`).

## Skills

Activate the matching skill before working in its domain:

- `gaeb-domain` — any change to parsing logic, fixture authoring, new
  format/driver work, or GAEB structure questions.
- `pr-review` — reviewing a PR, branch diff, or staged changes of this
  package.

## GAEB cheat sheet

- Phases: 80 service description (unrestricted superset) · 81 BoQ handover ·
  82 cost estimate · 83 tender request · 84 bid (prices!) · 85 side bid ·
  86 award · 87 order confirmation (X86 near-copy, AN→AG) · 89 invoice.
  Extensions `.x80`–`.x89`.
- Anatomy: `GAEB → GAEBInfo (version/date/program), PrjInfo (project),
  Award → DP (phase), AwardInfo (currency), BoQ → BoQInfo
  (label/currency/totals), BoQBody → BoQCtgy* (nest via own BoQBody) →
  Itemlist → Item*`.
- Position number `rNo` = ancestor `RNoPart`s + item `RNoPart`
  (+ `.RNoIndex` when present), dot-joined: `01.02.0010.1`.
- Deep reference: the gaeb-domain skill.
