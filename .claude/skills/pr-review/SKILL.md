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
