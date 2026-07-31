# Driver Split, Agent Guidance & Platform Bump — Design

Date: 2026-07-31
Status: Approved

## Goal

Four things, one round:

1. Restructure parsing behind an internal driver abstraction so older GAEB
   formats (GAEB 90, GAEB 2000) can be added later without changing the
   public API.
2. Strong agent guidance: a proper `CLAUDE.md` plus `.claude/skills/`
   (`gaeb-domain`, `pr-review`).
3. Target PHP 8.4/8.5 only (`"php": "^8.4"`).
4. GitHub Actions CI (matrix 8.4 + 8.5: pint --test, phpstan, pest).

## Non-Goals

- Implementing GAEB 90 or GAEB 2000 parsing (drivers exist to make that
  possible later, not to do it now)
- XSD validation, writing GAEB — unchanged non-goals
- Any change to the DTO graph

## Driver Architecture

```php
// src/Driver/Driver.php
interface Driver
{
    public function supports(string $content): bool;
    public function parse(string $content): GaebFile;
}

// src/Driver/GaebXmlDriver.php — the current parsing logic, moved verbatim
final class GaebXmlDriver implements Driver { ... }
```

- `GaebXmlDriver::supports()` returns true when the content is XML whose
  root element has local name `GAEB` (cheap check; full DOM load happens in
  `parse()` which may still throw `GaebParseException` for broken XML).
  Leading whitespace/BOM tolerated.
- `GaebParser` becomes a thin facade:

```php
final class GaebParser
{
    /** @param list<Driver> $drivers */
    public function __construct(private array $drivers = [new GaebXmlDriver]);

    public function parse(string $content): GaebFile;            // first supporting driver wins
    public function parseFile(string $path): GaebFile;           // readability check + parse()

    public static function fromFile(string $path): GaebFile;     // (new self)->parseFile()
    public static function fromString(string $content): GaebFile;
}
```

- **Backward compatibility:** `fromFile`/`fromString` keep exact behavior
  and exception messages for XML input; all existing tests pass unchanged.
- **Unsupported-format sniffing** (in `GaebParser::parse()`, when no driver
  supports the content):
  - content contains `#begin[` in the first 200 bytes → throw
    `GaebParseException('GAEB 2000 format detected — only GAEB DA XML is supported. …')`
  - first line matches `^\d{2}` and content is not XML → GAEB 90 message,
    same shape
  - content parses as XML but root is not `GAEB` → existing
    "Missing <GAEB> root element" message (thrown via the XML driver path)
  - otherwise → `GaebParseException('Unrecognized file format')`
- Custom drivers plug in via the constructor. No registry, no config.

## CLAUDE.md

Root-level, hand-written (no generator). Sections:

- **What this is** — one paragraph, scope + non-goals
- **Hard invariants** — zero runtime deps beyond ext-dom (never add
  without approval); lenient parsing contract (throw only for unreadable
  file / unparseable input / unrecognized format, everything optional →
  null); DTOs `final readonly`, public promoted properties, no
  interfaces/factories in the DTO layer; element lookup by local name only;
  every file `declare(strict_types=1)`
- **Architecture** — facade + driver split, where to add a new format
- **Verification** — `composer test` / `composer analyse` / `composer lint`
  green before any commit; never commit on red
- **Testing & fixtures** — Pest; fixtures are synthetic and self-authored;
  NEVER commit third-party GAEB files without verified redistribution
  rights (license chain must terminate at the copyright holder)
- **Comments** — lattice-style: self-explanatory code, comments only for
  *why*, delete "what" comments on sight
- **Git** — conventional commits, one logical change per commit, no agent
  attribution (no `Co-Authored-By`), no agent planning artifacts in
  commits (`docs/superpowers/`, `.superpowers/` stay local — see below)
- **Skills** — activate `gaeb-domain` when touching parsing/fixtures,
  `pr-review` when reviewing changes
- **GAEB primer** — ten-line cheat sheet (phases, anatomy), pointer to the
  gaeb-domain skill for the full reference

Note: `docs/superpowers/` is already committed history in this repo; the
rule applies to future work. Add `docs/superpowers/` to `.gitignore`?
No — keep committed specs, the rule forbids *new* planning artifacts in
feature commits. State exactly that in CLAUDE.md.

## Skills

`.claude/skills/gaeb-domain/SKILL.md` — reference knowledge:
- Format landscape: GAEB 90 (fixed-column records, `.d81`…), GAEB 2000
  (`#begin[...]` tagged text, `.p81`/`.d81`…), GAEB DA XML 3.x (`.x81`…)
- Exchange-phase table (81–86: what each phase is, which price fields are
  populated)
- XML anatomy tree (GAEBInfo / PrjInfo / Award / BoQ / BoQBody / BoQCtgy /
  Itemlist / Item / Description)
- Real-world element variants the parser must keep supporting:
  `Name` vs `NamePrj`, `Cur` in `PrjInfo`/`BoQInfo` vs `Award/AwardInfo`,
  `LblBoQ` vs `BoQInfo/Name`, `ProgSystem` vs `ProgName`, `RNoIndex`
- Position-number (`rNo`) composition rules incl. `RNoIndex` (`01.0010.1`)
- Text markup (`<p><span>` runs, flattening rules)
- Frontmatter `description` triggers: parsing changes, fixture authoring,
  format questions

`.claude/skills/pr-review/SKILL.md` — adapted from lattice:
- Same core: review-only, four dimensions (reuse / simplification /
  efficiency / altitude), confidence gate, trace-correctness-doubts rule,
  what-NOT-to-flag list, findings output format
- Guideline checks swapped for this package: hard invariants from
  CLAUDE.md, fixture/license policy, comments rule, conventional commits
  without attribution
- Examples rewritten as plain-PHP (no Laravel/Inertia/React content)
- Worktree note simplified to plain `git worktree` (no lattice worktrees
  skill dependency)

## Platform

- `composer.json`: `"php": "^8.4"` (covers 8.4 + 8.5). No code changes
  required; do not retrofit 8.4-only features.
- `.github/workflows/ci.yml`: on push to main + PRs; matrix `php: [8.4, 8.5]`;
  steps: checkout, setup-php (with dom), composer install (cached),
  `composer test:lint`, `composer analyse`, `composer test`.

## Testing

- All 13 existing tests pass unchanged (BC proof).
- New tests: instance API (`(new GaebParser)->parse(...)`), custom driver
  injection (a tiny fake driver in-test), GAEB 2000 sniff message,
  GAEB 90 sniff message, unrecognized-format message.
