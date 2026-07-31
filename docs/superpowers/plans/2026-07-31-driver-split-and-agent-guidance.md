# Driver Split & Agent Guidance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restructure parsing behind an internal driver abstraction, add strong agent guidance (CLAUDE.md + two skills), bump to PHP ^8.4, add CI.

**Architecture:** `GaebParser` becomes a thin facade over a `Driver` interface; all current XML logic moves verbatim into `GaebXmlDriver`. Static entry points stay byte-for-byte behavior-compatible. Docs/skills are authored content specified fully in this plan.

**Tech Stack:** PHP ^8.4, ext-dom, Pest, PHPStan lvl 5, Pint, GitHub Actions.

## Global Constraints

- **Commit messages: conventional-commit subject, NO `Co-Authored-By` or any agent attribution trailer** (new repo rule, applies to all tasks in this plan)
- Every PHP file starts with `<?php declare(strict_types=1);`
- DTO layer unchanged; element lookup by local name only
- Zero runtime deps beyond ext-dom
- Before each commit: `composer lint && composer analyse && composer test` green, pristine output
- Spec: `docs/superpowers/specs/2026-07-31-driver-split-and-agent-guidance-design.md`

---

### Task 1: Driver split

**Files:**
- Create: `src/Driver/Driver.php`, `src/Driver/GaebXmlDriver.php`
- Modify: `src/GaebParser.php` (becomes facade)
- Test: `tests/DriverTest.php` (new); all 13 existing tests must pass unchanged

**Interfaces:**
- Consumes: current `src/GaebParser.php` (read it first — its private methods move verbatim)
- Produces: `Driver{supports(string $content): bool, parse(string $content): GaebFile}`; `GaebParser{__construct(list<Driver> $drivers = [new GaebXmlDriver]), parse(string): GaebFile, parseFile(string): GaebFile, static fromFile(string): GaebFile, static fromString(string): GaebFile}`

- [ ] **Step 1: Write the failing tests**

`tests/DriverTest.php`:
```php
<?php declare(strict_types=1);

use Bambamboole\GaebParser\Driver\Driver;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\ProjectInfo;
use Bambamboole\GaebParser\GaebParseException;
use Bambamboole\GaebParser\GaebParser;

it('parses via the instance api', function () {
    $content = file_get_contents(__DIR__.'/fixtures/boq.x83');
    $gaeb = (new GaebParser)->parse($content);

    expect($gaeb->boq)->not->toBeNull();
});

it('parses a file via the instance api', function () {
    $gaeb = (new GaebParser)->parseFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->info->phase)->toBe(83);
});

it('uses the first supporting custom driver', function () {
    $canned = new GaebFile(new GaebInfo('9.9', null, null, null), new ProjectInfo(null, null, null), null);
    $driver = new class($canned) implements Driver
    {
        public function __construct(private readonly GaebFile $result) {}

        public function supports(string $content): bool
        {
            return true;
        }

        public function parse(string $content): GaebFile
        {
            return $this->result;
        }
    };

    expect((new GaebParser([$driver]))->parse('anything'))->toBe($canned);
});

it('rejects gaeb 2000 content with a clear error', function () {
    (new GaebParser)->parse("#begin[GAEB]\n#end[GAEB]");
})->throws(GaebParseException::class, 'GAEB 2000');

it('rejects gaeb 90 content with a clear error', function () {
    (new GaebParser)->parse("00K\n01Projekt XY\n");
})->throws(GaebParseException::class, 'GAEB 90');

it('rejects unrecognized content', function () {
    (new GaebParser)->parse('certainly not gaeb');
})->throws(GaebParseException::class, 'Unrecognized file format');
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/pest tests/DriverTest.php`
Expected: FAIL — `Interface "Bambamboole\GaebParser\Driver\Driver" not found` / missing methods

- [ ] **Step 3: Create the Driver interface**

`src/Driver/Driver.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Driver;

use Bambamboole\GaebParser\Dto\GaebFile;

interface Driver
{
    public function supports(string $content): bool;

    public function parse(string $content): GaebFile;
}
```

- [ ] **Step 4: Create GaebXmlDriver by moving the XML logic**

`src/Driver/GaebXmlDriver.php` — namespace `Bambamboole\GaebParser\Driver`, imports: the five `Bambamboole\GaebParser\Dto\*` classes plus `Bambamboole\GaebParser\GaebParseException`.

```php
final class GaebXmlDriver implements Driver
{
    public function supports(string $content): bool
    {
        return str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\r\n"), '<');
    }

    public function parse(string $content): GaebFile
    {
        // EXACT body of the current GaebParser::fromString(), unchanged
        // (DOMDocument load, Invalid XML throw, GAEB-root check, GaebFile construction)
    }

    // Move ALL private static methods from the current src/GaebParser.php
    // VERBATIM (no edits, same order): parseInfo, parseProject, parseBoQ,
    // parseBody, parseCategory, parseItem, child, text, children, floatVal,
    // flatten — including their docblocks.
}
```

- [ ] **Step 5: Rewrite GaebParser as facade**

`src/GaebParser.php` (complete new content):
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Driver\Driver;
use Bambamboole\GaebParser\Driver\GaebXmlDriver;
use Bambamboole\GaebParser\Dto\GaebFile;

final class GaebParser
{
    /** @param list<Driver> $drivers */
    public function __construct(private readonly array $drivers = [new GaebXmlDriver]) {}

    public static function fromFile(string $path): GaebFile
    {
        return (new self)->parseFile($path);
    }

    public static function fromString(string $content): GaebFile
    {
        return (new self)->parse($content);
    }

    public function parseFile(string $path): GaebFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return $this->parse($content);
    }

    public function parse(string $content): GaebFile
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($content)) {
                return $driver->parse($content);
            }
        }

        throw $this->unrecognized($content);
    }

    private function unrecognized(string $content): GaebParseException
    {
        $head = ltrim(substr($content, 0, 200));
        if (str_contains($head, '#begin[')) {
            return new GaebParseException('GAEB 2000 format detected — only GAEB DA XML is currently supported');
        }
        if (preg_match('/^\d{2}/', $head) === 1) {
            return new GaebParseException('GAEB 90 format detected — only GAEB DA XML is currently supported');
        }

        return new GaebParseException('Unrecognized file format');
    }
}
```

Behavior notes: the previous `fromString('not xml at all')` threw `Invalid XML`; it now throws `Unrecognized file format`. The existing test asserts only the exception class, so it stays green. All XML-input paths keep identical messages via `GaebXmlDriver`.

- [ ] **Step 6: Run the full suite**

Run: `vendor/bin/pest`
Expected: 19 tests pass (13 existing + 6 new), pristine output.

- [ ] **Step 7: Lint, analyse, commit**

Run: `composer lint && composer analyse && composer test`

```bash
git add src tests
git commit -m "refactor: split parsing into driver architecture"
```

---

### Task 2: PHP ^8.4 + CI

**Files:**
- Modify: `composer.json`, `composer.lock`
- Create: `.github/workflows/ci.yml`

**Interfaces:**
- Consumes: Task 1's green suite
- Produces: platform floor `^8.4`; CI running lint/analyse/test on 8.4 + 8.5

- [ ] **Step 1: Bump the platform requirement**

In `composer.json` change `"php": "^8.3"` to `"php": "^8.4"`, then run `composer update` to refresh the lock. Run `composer test` — still 19 passing.

- [ ] **Step 2: Write the workflow**

`.github/workflows/ci.yml`:
```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  test:
    runs-on: ubuntu-latest
    strategy:
      fail-fast: false
      matrix:
        php: ['8.4', '8.5']
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: ${{ matrix.php }}
          extensions: dom
          coverage: none
      - uses: ramsey/composer-install@v3
      - run: composer test:lint
      - run: composer analyse
      - run: composer test
```

- [ ] **Step 3: Verify and commit**

Run: `composer lint && composer analyse && composer test` (green), then:

```bash
git add composer.json composer.lock .github
git commit -m "chore: require php 8.4 and add ci workflow"
```

---

### Task 3: CLAUDE.md

**Files:**
- Create: `CLAUDE.md`

**Interfaces:**
- Consumes: Task 1's architecture (facade + drivers)
- Produces: the repo's agent guidance; Task 4's skills are referenced by the names used here

- [ ] **Step 1: Write CLAUDE.md** (content below is the deliverable, verbatim):

````markdown
# gaeb-parser

Small, dependency-free PHP library parsing GAEB DA XML files (German
construction-tender exchange format) into readonly PHP objects. Read-only
and lenient. Supports GAEB DA XML 3.x (phases 81–86); GAEB 90 and
GAEB 2000 are detected but rejected with a clear error — the driver seam
exists so they can be added later.

## Hard invariants (never break without approval)

- **Zero runtime dependencies beyond `ext-dom`.** Never add a composer
  runtime dependency.
- **Lenient parsing contract:** `GaebParseException` is thrown ONLY for
  unreadable files, unparseable/unrecognized input, or a missing `<GAEB>`
  root. Every missing or optional element yields `null` (or an empty
  list) — never throw over content.
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
  class implementing `Bambamboole\GaebParser\Driver\Driver` that maps
  into the existing DTO graph — never bolt format branches into the
  facade, never invent a parallel object model.
- DTO graph: `GaebFile → GaebInfo / ProjectInfo / BoQ → BoQCategory /
  Item`. Phase differences are nullable properties; one model for all
  phases.

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
- Real-world element variants matter more than naive spec reading: keep
  coverage for `NamePrj`, `Award/AwardInfo/Cur`, `BoQInfo/Name`,
  `ProgName`, and `RNoIndex` (see the gaeb-domain skill).

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
- Never commit agent planning artifacts or scratch files (`.superpowers/`
  and similar). Approved design specs under `docs/superpowers/specs/` are
  the deliberate exception.

## Skills

Activate the matching skill before working in its domain:

- `gaeb-domain` — any change to parsing logic, fixture authoring, new
  format/driver work, or GAEB structure questions.
- `pr-review` — reviewing a PR, branch diff, or staged changes of this
  package.

## GAEB cheat sheet

- Phases: 81 BoQ handover · 82 cost estimate · 83 tender request ·
  84 bid (prices!) · 85 side bid · 86 award. Extensions `.x81`–`.x86`.
- Anatomy: `GAEB → GAEBInfo (version/date/program), PrjInfo (project),
  Award → DP (phase), AwardInfo (currency), BoQ → BoQInfo
  (label/currency/totals), BoQBody → BoQCtgy* (nest via own BoQBody) →
  Itemlist → Item*`.
- Position number `rNo` = ancestor `RNoPart`s + item `RNoPart`
  (+ `.RNoIndex` when present), dot-joined: `01.02.0010.1`.
- Deep reference: the gaeb-domain skill.
````

- [ ] **Step 2: Commit**

```bash
git add CLAUDE.md
git commit -m "docs: add agent guidance in CLAUDE.md"
```

---

### Task 4: Skills — gaeb-domain and pr-review

**Files:**
- Create: `.claude/skills/gaeb-domain/SKILL.md`, `.claude/skills/pr-review/SKILL.md`

**Interfaces:**
- Consumes: CLAUDE.md rule names from Task 3 (skills must not contradict it)
- Produces: the two skills CLAUDE.md references

- [ ] **Step 1: Write `.claude/skills/gaeb-domain/SKILL.md`** (verbatim):

````markdown
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
````

- [ ] **Step 2: Write `.claude/skills/pr-review/SKILL.md`** (verbatim):

````markdown
---
name: pr-review
description: Use when reviewing a gaeb-parser pull request, branch diff, or set of staged/working changes for quality — reuse, simplification, efficiency, and altitude cleanups, plus adherence to the project guidelines. Review-only; it surfaces findings and never commits the changes it proposes.
---

# gaeb-parser PR Review

Review changed code for **quality**: is the change as simple, reused,
efficient, and well-placed as it should be, and does it follow the
project guidelines in CLAUDE.md?

This is **not** a broad bug hunt — a full correctness sweep is a separate
review. But "not a bug hunt" is not a licence to ignore correctness you
stumble into: when a hunk you are already reading raises a *concrete*
correctness doubt, **trace it to ground before deciding** — follow the
data path, read the code, confirm whether the defect is real. Report a
verified defect as a finding, or a genuinely ambiguous one as a question.
Never defer an un-investigated suspicion.

**Core principle:** every finding must be behavior-preserving and earn
its place. Suggest the change a senior engineer would actually make —
not a restyle, not a nitpick a linter already catches.

## Operating Rules (non-negotiable)

- **Review-only. Never commit, push, or leave proposed changes as the
  deliverable.** Output is findings.
- Prefer a dedicated `git worktree` for the review when the main checkout
  may hold in-progress work; plain in-place review is fine when the tree
  is clean and the user asked for it.
- You **may** edit code locally to *verify a path* (confirm a refactor
  passes `composer analyse`, that a test still passes). Revert scratch
  edits afterwards.
- **Scope to the diff.** Only review lines the change added or touched,
  plus the immediate context needed to judge them.
- **Preserve behavior.** If a suggestion changes outputs or edge-case
  handling, it is a question, not a cleanup.
- **Confidence gate.** Only surface findings you are confident improve
  the code. A short list of real improvements beats a long list of
  maybes.

## The Four Dimensions

### 1. Reuse — *is this reinventing something we already have?*

- Duplicated blocks that should be one function.
- A hand-rolled helper mirroring an existing one (`child()`,
  `children()`, `text()`, `floatVal()`, `flatten()` in `GaebXmlDriver`)
  or an SPL/stdlib function.
- Re-deriving a value a DTO already carries.

Before claiming "this already exists", grep and confirm.

```php
// ❌ reimplements the existing text() helper
$node = null;
foreach ($el->childNodes as $child) {
    if ($child instanceof \DOMElement && $child->localName === 'Cur') {
        $node = $child;
    }
}
$currency = $node !== null ? trim($node->textContent) : null;

// ✅ reuse
$currency = self::text($el, 'Cur');
```

### 2. Simplification — *is this more complex than the job needs?*

Dead code, redundant conditionals, one-caller indirection, needless
nesting, flags that are always the same value, defensive checks that
cannot trigger. Explicit beats clever: prefer guard clauses over nested
ternaries; do not compress into dense one-liners.

### 3. Efficiency — *does this do needless work?*

Re-parsing the same DOM, repeated `getElementsByTagNameNS` scans of the
same subtree, loading whole files repeatedly in a loop. Do not
micro-optimize cold paths at the cost of readability — this library
parses a file once; clarity wins ties.

### 4. Altitude — *is the code at the right layer?*

- Format-specific logic in the `GaebParser` facade instead of a driver.
- DOM traversal leaking into DTOs (DTOs are pure data — always).
- Driver code constructing exception messages that belong to the facade's
  sniffing, or vice versa.

## Guideline Adherence (highest-signal checks)

Weight these — they are the package's hard invariants (full text in
CLAUDE.md):

- **No new runtime dependencies** (ext-dom only).
- **Lenient contract:** no new throw paths over content; optional →
  `null`.
- **DTO layer:** `final readonly`, public promoted properties, no
  behavior beyond trivial accessors/generators.
- **Local-name lookup only** — flag any namespace- or prefix-sensitive
  matching.
- **`declare(strict_types=1)`** first line of every PHP file.
- **Fixtures:** synthetic only; flag any third-party file without a
  clean license chain to the copyright holder.
- **Comments:** no "what" comments; delete obsolete ones in touched code.
- **Git:** one logical change per commit, conventional subject, no agent
  attribution.

## What NOT to Flag

- Anything Pint, PHPStan, or the test suite catches — the gate runs
  separately.
- Pre-existing issues on untouched lines.
- Pedantic nitpicks a senior engineer would wave through.
- Intentional changes clearly tied to the change's purpose.
- General "add more tests/docs" wishes unless a guideline requires it.

## Workflow

1. Establish the diff: `git diff main...HEAD` (branch),
   `git diff` (uncommitted), or `gh pr diff <n>` (PR).
2. Read each changed hunk through the four dimensions, then the
   guideline checks.
3. For any non-obvious finding, **verify the path**: grep for the
   existing helper, or apply the refactor locally, run
   `composer analyse` / `vendor/bin/pest --filter=...`, then revert.
4. Apply the confidence gate; drop the maybes.
5. Report findings only. Do not commit.

## Findings Output Format

```
### PR Review — <branch or #PR>

Found N findings.

1. [Reuse] src/Driver/GaebXmlDriver.php:142
   Reimplements text() inline. Call the existing helper.

2. [Guideline: lenient contract] src/Driver/GaebXmlDriver.php:88
   New throw for a missing optional element. Return null instead.
```

If nothing meets the gate:

```
### PR Review — <branch or #PR>

No findings. Checked reuse, simplification, efficiency, altitude, and
guideline adherence.
```

## Common Mistakes

- Presenting scratch verification edits as the deliverable. Revert them.
- Flagging behavior changes as "cleanups".
- Reviewing whole files instead of the diff.
- Listing low-confidence nitpicks to look thorough.
- Recommending a helper without grepping to confirm it exists and fits.
- Hiding behind "not a bug hunt" to skip a correctness doubt you already
  noticed.
````

- [ ] **Step 3: Verify and commit**

Verify both files have valid frontmatter (name + description) and the names match those referenced in CLAUDE.md (`gaeb-domain`, `pr-review`).

```bash
git add .claude
git commit -m "docs: add gaeb-domain and pr-review skills"
```
