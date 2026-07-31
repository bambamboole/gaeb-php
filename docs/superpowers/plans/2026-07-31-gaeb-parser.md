# GAEB Parser Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A read-only PHP library parsing GAEB DA XML 3.3 files (phases X81–X86) into readonly DTOs.

**Architecture:** One `GaebParser` class walks the DOM with namespace-agnostic local-name lookups and builds a small graph of `final readonly` DTOs (`GaebFile`, `GaebInfo`, `ProjectInfo`, `BoQ`, `BoQCategory`, `Item`). Lenient: missing optionals become null; only unreadable input / non-GAEB XML throws `GaebParseException`.

**Tech Stack:** PHP ^8.3, ext-dom (no other runtime deps). Dev: Pest, PHPStan level 5, Pint (laravel preset + strict types).

## Global Constraints

- Package name `bambamboole/gaeb-parser`, MIT license, PHP `^8.3`
- Runtime dependencies: none beyond `ext-dom`
- PSR-4: `Bambamboole\GaebParser\` → `src/`, `Tests\` → `tests/`
- All DTOs `final readonly` with public promoted properties; no interfaces, no factories
- Every PHP file starts with `<?php declare(strict_types=1);`
- Element lookup is by **local name** only — never match on namespace/prefix
- Spec: `docs/superpowers/specs/2026-07-31-gaeb-parser-design.md`

## GAEB 3.3 domain primer (read before any task)

GAEB DA XML is the German construction-tender exchange format. A file looks like:

```xml
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo><Version>3.3</Version><Date>…</Date><ProgSystem>…</ProgSystem></GAEBInfo>
  <PrjInfo><Name>…</Name><LblPrj>…</LblPrj><Cur>EUR</Cur></PrjInfo>
  <Award>
    <DP>83</DP>                                   <!-- exchange phase 81–86 -->
    <BoQ>
      <BoQInfo><LblBoQ>…</LblBoQ><Cur>EUR</Cur><Totals><Total>…</Total></Totals></BoQInfo>
      <BoQBody>
        <BoQCtgy RNoPart="01">                    <!-- category, nests via its own BoQBody -->
          <LblTx><p><span>Erdarbeiten</span></p></LblTx>
          <BoQBody>
            <Itemlist>
              <Item RNoPart="0010">               <!-- position -->
                <Qty>100.000</Qty><QU>m3</QU>
                <Description><CompleteText>
                  <DetailTxt><Text><p><span>long text…</span></p></Text></DetailTxt>
                  <OutlineText><OutlTxt><TextOutlTxt><p><span>short text</span></p></TextOutlTxt></OutlTxt></OutlineText>
                </CompleteText></Description>
                <UP>12.50</UP><IT>1250.00</IT>    <!-- unit/total price, priced phases only -->
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQCtgy>
      </BoQBody>
    </BoQ>
  </Award>
</GAEB>
```

The namespace varies per phase (`…/DA83/3.3`, `…/DA84/3.3`, …) — hence local-name matching. The full position number (`rNo`) is the ancestor categories' `RNoPart`s plus the item's, joined with `.` (e.g. `01.0010`). Texts are XHTML-ish `<p><span>` markup; we flatten paragraphs to plain text joined by `\n`.

---

### Task 1: Project scaffolding

**Files:**
- Create: `composer.json`, `phpunit.xml`, `pint.json`, `phpstan.neon.dist`, `.gitignore`

**Interfaces:**
- Consumes: nothing
- Produces: working `composer test`, `composer analyse`, `composer lint` for all later tasks

- [ ] **Step 1: Write composer.json**

```json
{
    "name": "bambamboole/gaeb-parser",
    "description": "A small PHP library to parse GAEB DA XML 3.3 files into typed PHP objects.",
    "keywords": ["bambamboole", "gaeb", "xml", "parser", "ava", "boq"],
    "license": "MIT",
    "type": "library",
    "authors": [
        {
            "name": "Manuel Christlieb",
            "email": "manuel@christlieb.eu"
        }
    ],
    "require": {
        "php": "^8.3",
        "ext-dom": "*"
    },
    "require-dev": {
        "laravel/pint": "^1.16",
        "pestphp/pest": "^3.8",
        "phpstan/phpstan": "^2.1"
    },
    "autoload": {
        "psr-4": {
            "Bambamboole\\GaebParser\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Tests\\": "tests/"
        }
    },
    "scripts": {
        "analyse": "vendor/bin/phpstan analyse",
        "lint": "vendor/bin/pint",
        "test:lint": "vendor/bin/pint --test",
        "test": "vendor/bin/pest"
    },
    "config": {
        "sort-packages": true,
        "allow-plugins": {
            "pestphp/pest-plugin": true
        }
    },
    "minimum-stability": "stable",
    "prefer-stable": true
}
```

- [ ] **Step 2: Write config files**

`phpunit.xml`:
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

`pint.json`:
```json
{
    "preset": "laravel",
    "rules": {
        "declare_strict_types": true,
        "blank_line_after_opening_tag": false,
        "linebreak_after_opening_tag": false
    }
}
```

`phpstan.neon.dist`:
```yaml
parameters:
    paths:
        - src
    level: 5
```

`.gitignore`:
```
/vendor/
/.phpunit.cache/
```

- [ ] **Step 3: Install dependencies**

Run: `composer install`
Expected: success, `vendor/bin/pest` exists.

- [ ] **Step 4: Verify toolchain**

Run: `vendor/bin/pest --version && composer test:lint`
Expected: Pest version prints; pint passes (no PHP files yet is fine). `composer test` may warn "no tests" — acceptable at this point.

- [ ] **Step 5: Commit**

```bash
git add composer.json composer.lock phpunit.xml pint.json phpstan.neon.dist .gitignore
git commit -m "chore: scaffold package tooling"
```

---

### Task 2: Exceptions, entry points, file/project metadata

**Files:**
- Create: `src/GaebParseException.php`, `src/GaebParser.php`, `src/Dto/GaebFile.php`, `src/Dto/GaebInfo.php`, `src/Dto/ProjectInfo.php`
- Test: `tests/GaebParserTest.php`, `tests/fixtures/minimal.x83`

**Interfaces:**
- Consumes: Task 1 toolchain
- Produces: `GaebParser::fromFile(string $path): GaebFile`, `GaebParser::fromString(string $xml): GaebFile`. `GaebFile` has ONLY `GaebInfo $info` and `ProjectInfo $project` in this task — Task 3 adds the `?BoQ $boq` property. Later tasks rely on: `GaebInfo{?string $version, ?int $phase, ?string $date, ?string $program}`, `ProjectInfo{?string $name, ?string $label, ?string $currency}`, and the private parser helpers `child()`, `children()`, `text()`, `floatVal()`, `flatten()` reused by Tasks 3–4.

- [ ] **Step 1: Write fixture `tests/fixtures/minimal.x83`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo>
    <Version>3.3</Version>
    <Date>2024-01-15</Date>
    <ProgSystem>TestAVA 1.0</ProgSystem>
  </GAEBInfo>
  <PrjInfo>
    <Name>PRJ-1</Name>
    <LblPrj>Neubau Testhalle</LblPrj>
    <Cur>EUR</Cur>
  </PrjInfo>
  <Award>
    <DP>83</DP>
  </Award>
</GAEB>
```

- [ ] **Step 2: Write the failing tests**

`tests/GaebParserTest.php`:
```php
<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParseException;
use Bambamboole\GaebParser\GaebParser;

it('throws on invalid xml', function () {
    GaebParser::fromString('not xml at all');
})->throws(GaebParseException::class);

it('throws on xml without GAEB root', function () {
    GaebParser::fromString('<?xml version="1.0"?><Other/>');
})->throws(GaebParseException::class);

it('throws on unreadable file', function () {
    GaebParser::fromFile(__DIR__.'/fixtures/does-not-exist.x83');
})->throws(GaebParseException::class);

it('parses file and project metadata', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->info->version)->toBe('3.3')
        ->and($gaeb->info->phase)->toBe(83)
        ->and($gaeb->info->date)->toBe('2024-01-15')
        ->and($gaeb->info->program)->toBe('TestAVA 1.0')
        ->and($gaeb->project->name)->toBe('PRJ-1')
        ->and($gaeb->project->label)->toBe('Neubau Testhalle')
        ->and($gaeb->project->currency)->toBe('EUR');
});

it('detects phase from namespace when DP element is missing', function () {
    $gaeb = GaebParser::fromString(
        '<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA84/3.3"><Award/></GAEB>'
    );

    expect($gaeb->info->phase)->toBe(84);
});

it('parses leniently when optional metadata is missing', function () {
    $gaeb = GaebParser::fromString('<GAEB><Award/></GAEB>');

    expect($gaeb->info->version)->toBeNull()
        ->and($gaeb->info->phase)->toBeNull()
        ->and($gaeb->project->name)->toBeNull();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest`
Expected: FAIL — `Class "Bambamboole\GaebParser\GaebParser" not found`

- [ ] **Step 4: Write the implementation**

`src/GaebParseException.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

class GaebParseException extends \RuntimeException {}
```

`src/Dto/GaebInfo.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class GaebInfo
{
    public function __construct(
        public ?string $version,
        public ?int $phase,
        public ?string $date,
        public ?string $program,
    ) {}
}
```

`src/Dto/ProjectInfo.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class ProjectInfo
{
    public function __construct(
        public ?string $name,
        public ?string $label,
        public ?string $currency,
    ) {}
}
```

`src/Dto/GaebFile.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class GaebFile
{
    public function __construct(
        public GaebInfo $info,
        public ProjectInfo $project,
    ) {}
}
```

`src/GaebParser.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\ProjectInfo;

final class GaebParser
{
    public static function fromFile(string $path): GaebFile
    {
        $xml = @file_get_contents($path);
        if ($xml === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return self::fromString($xml);
    }

    public static function fromString(string $xml): GaebFile
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new GaebParseException('Invalid XML');
        }

        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root),
        );
    }

    private static function parseInfo(\DOMElement $root): GaebInfo
    {
        $info = self::child($root, 'GAEBInfo');
        $award = self::child($root, 'Award');

        $phase = null;
        if ($award !== null && ($dp = self::text($award, 'DP')) !== null) {
            $phase = (int) $dp;
        } elseif (preg_match('~/DA(8\d)/~', (string) $root->namespaceURI, $m) === 1) {
            $phase = (int) $m[1];
        }

        return new GaebInfo(
            version: $info !== null ? self::text($info, 'Version') : null,
            phase: $phase,
            date: $info !== null ? self::text($info, 'Date') : null,
            program: $info !== null ? self::text($info, 'ProgSystem') : null,
        );
    }

    private static function parseProject(\DOMElement $root): ProjectInfo
    {
        $prj = self::child($root, 'PrjInfo');

        return new ProjectInfo(
            name: $prj !== null ? self::text($prj, 'Name') : null,
            label: $prj !== null ? self::text($prj, 'LblPrj') : null,
            currency: $prj !== null ? self::text($prj, 'Cur') : null,
        );
    }

    private static function child(\DOMElement $el, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    /** @return list<\DOMElement> */
    private static function children(\DOMElement $el, string $name): array
    {
        $result = [];
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                $result[] = $node;
            }
        }

        return $result;
    }

    private static function text(\DOMElement $el, string $name): ?string
    {
        $node = self::child($el, $name);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    private static function floatVal(\DOMElement $el, string $name): ?float
    {
        $value = self::text($el, $name);

        return $value === null ? null : (float) $value;
    }

    /** Flatten GAEB rich text (<p><span>…) to plain text, paragraphs joined by \n. */
    private static function flatten(?\DOMElement $el): ?string
    {
        if ($el === null) {
            return null;
        }
        $paragraphs = $el->getElementsByTagNameNS('*', 'p');
        if ($paragraphs->length === 0) {
            $value = trim($el->textContent);

            return $value === '' ? null : $value;
        }
        $lines = [];
        foreach ($paragraphs as $p) {
            $line = trim($p->textContent);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
```

Note: `children()`, `floatVal()`, `flatten()` are unused until Task 3 — if pint/phpstan complain about unused private methods, keep them anyway (suppress not needed at level 5; phpstan does not flag unused private static methods at this level — if it does, move their introduction to Task 3).

- [ ] **Step 5: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all 6 tests PASS

- [ ] **Step 6: Lint, analyse, commit**

Run: `composer lint && composer analyse && composer test`
Expected: clean.

```bash
git add src tests
git commit -m "feat: parse GAEB file and project metadata with lenient error handling"
```

---

### Task 3: BoQ tree — categories, items, texts

**Files:**
- Create: `src/Dto/BoQ.php`, `src/Dto/BoQCategory.php`, `src/Dto/Item.php`, `tests/fixtures/boq.x83`
- Modify: `src/Dto/GaebFile.php` (add `?BoQ $boq`), `src/GaebParser.php` (add BoQ parsing)
- Test: `tests/BoQParsingTest.php`

**Interfaces:**
- Consumes: `GaebParser` helpers from Task 2: `child(\DOMElement, string): ?\DOMElement`, `children(\DOMElement, string): array`, `text(\DOMElement, string): ?string`, `floatVal(\DOMElement, string): ?float`, `flatten(?\DOMElement): ?string`
- Produces: `GaebFile->boq: ?BoQ`; `BoQ{?string $label, ?string $currency, ?float $total, list<BoQCategory> $categories, list<Item> $items, allItems(): \Generator}` (allItems is fully implemented here; Task 4 tests it); `BoQCategory{string $rNoPart, ?string $label, list<BoQCategory> $categories, list<Item> $items}`; `Item{string $rNo, string $rNoPart, ?float $qty, ?string $unit, ?string $shortText, ?string $longText, ?string $descriptionXml, ?float $unitPrice, ?float $totalPrice, bool $lumpSum}`

- [ ] **Step 1: Write fixture `tests/fixtures/boq.x83`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
  <GAEBInfo><Version>3.3</Version></GAEBInfo>
  <PrjInfo><Name>PRJ-1</Name><Cur>EUR</Cur></PrjInfo>
  <Award>
    <DP>83</DP>
    <BoQ>
      <BoQInfo>
        <LblBoQ>Leistungsverzeichnis Testhalle</LblBoQ>
        <Cur>EUR</Cur>
      </BoQInfo>
      <BoQBody>
        <BoQCtgy RNoPart="01">
          <LblTx><p><span>Erdarbeiten</span></p></LblTx>
          <BoQBody>
            <BoQCtgy RNoPart="02">
              <LblTx><p><span>Aushub</span></p></LblTx>
              <BoQBody>
                <Itemlist>
                  <Item RNoPart="0010">
                    <Qty>100.000</Qty>
                    <QU>m3</QU>
                    <Description>
                      <CompleteText>
                        <DetailTxt>
                          <Text><p><span>Boden loesen und lagern.</span></p><p><span>Bodenklasse 3-5.</span></p></Text>
                        </DetailTxt>
                        <OutlineText>
                          <OutlTxt><TextOutlTxt><p><span>Boden loesen</span></p></TextOutlTxt></OutlTxt>
                        </OutlineText>
                      </CompleteText>
                    </Description>
                  </Item>
                  <Item RNoPart="0020">
                    <QU>psch</QU>
                    <LumpSumItem>Yes</LumpSumItem>
                    <Description>
                      <CompleteText>
                        <OutlineText>
                          <OutlTxt><TextOutlTxt><p><span>Baustelle einrichten</span></p></TextOutlTxt></OutlTxt>
                        </OutlineText>
                      </CompleteText>
                    </Description>
                  </Item>
                </Itemlist>
              </BoQBody>
            </BoQCtgy>
          </BoQBody>
        </BoQCtgy>
      </BoQBody>
    </BoQ>
  </Award>
</GAEB>
```

- [ ] **Step 2: Write the failing tests**

`tests/BoQParsingTest.php`:
```php
<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('returns null boq when the file has none', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->boq)->toBeNull();
});

it('parses the category tree', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;

    expect($boq->label)->toBe('Leistungsverzeichnis Testhalle')
        ->and($boq->currency)->toBe('EUR')
        ->and($boq->categories)->toHaveCount(1);

    $erdarbeiten = $boq->categories[0];
    expect($erdarbeiten->rNoPart)->toBe('01')
        ->and($erdarbeiten->label)->toBe('Erdarbeiten')
        ->and($erdarbeiten->categories)->toHaveCount(1);

    $aushub = $erdarbeiten->categories[0];
    expect($aushub->rNoPart)->toBe('02')
        ->and($aushub->label)->toBe('Aushub')
        ->and($aushub->items)->toHaveCount(2);
});

it('parses items with quantities, texts and flags', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;
    [$first, $second] = $boq->categories[0]->categories[0]->items;

    expect($first->rNo)->toBe('01.02.0010')
        ->and($first->rNoPart)->toBe('0010')
        ->and($first->qty)->toBe(100.0)
        ->and($first->unit)->toBe('m3')
        ->and($first->shortText)->toBe('Boden loesen')
        ->and($first->longText)->toBe("Boden loesen und lagern.\nBodenklasse 3-5.")
        ->and($first->descriptionXml)->toContain('<DetailTxt>')
        ->and($first->unitPrice)->toBeNull()
        ->and($first->lumpSum)->toBeFalse();

    expect($second->rNo)->toBe('01.02.0020')
        ->and($second->qty)->toBeNull()
        ->and($second->unit)->toBe('psch')
        ->and($second->shortText)->toBe('Baustelle einrichten')
        ->and($second->longText)->toBeNull()
        ->and($second->lumpSum)->toBeTrue();
});
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `vendor/bin/pest tests/BoQParsingTest.php`
Expected: FAIL — `boq` property does not exist / `BoQ` class not found

- [ ] **Step 4: Write the DTOs**

`src/Dto/Item.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class Item
{
    public function __construct(
        public string $rNo,
        public string $rNoPart,
        public ?float $qty,
        public ?string $unit,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public ?float $unitPrice,
        public ?float $totalPrice,
        public bool $lumpSum,
    ) {}
}
```

`src/Dto/BoQCategory.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class BoQCategory
{
    /**
     * @param  list<BoQCategory>  $categories
     * @param  list<Item>  $items
     */
    public function __construct(
        public string $rNoPart,
        public ?string $label,
        public array $categories,
        public array $items,
    ) {}
}
```

`src/Dto/BoQ.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class BoQ
{
    /**
     * @param  list<BoQCategory>  $categories
     * @param  list<Item>  $items
     */
    public function __construct(
        public ?string $label,
        public ?string $currency,
        public ?float $total,
        public array $categories,
        public array $items,
    ) {}

    /** @return \Generator<int, Item> */
    public function allItems(): \Generator
    {
        yield from $this->items;
        yield from self::walk($this->categories);
    }

    /**
     * @param  list<BoQCategory>  $categories
     * @return \Generator<int, Item>
     */
    private static function walk(array $categories): \Generator
    {
        foreach ($categories as $category) {
            yield from $category->items;
            yield from self::walk($category->categories);
        }
    }
}
```

Modify `src/Dto/GaebFile.php`:
```php
<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class GaebFile
{
    public function __construct(
        public GaebInfo $info,
        public ProjectInfo $project,
        public ?BoQ $boq,
    ) {}
}
```

- [ ] **Step 5: Extend GaebParser**

In `src/GaebParser.php` add `use Bambamboole\GaebParser\Dto\BoQ;`, `use Bambamboole\GaebParser\Dto\BoQCategory;`, `use Bambamboole\GaebParser\Dto\Item;` and change `fromString()`'s return to:

```php
        $award = self::child($root, 'Award');

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root),
            boq: $award !== null ? self::parseBoQ($award) : null,
        );
```

(`parseInfo` already looks up `Award` itself; keeping both lookups is fine — they are cheap. Do not refactor `parseInfo`.)

Add these methods:

```php
    private static function parseBoQ(\DOMElement $award): ?BoQ
    {
        $boq = self::child($award, 'BoQ');
        if ($boq === null) {
            return null;
        }

        $info = self::child($boq, 'BoQInfo');
        $totals = $info !== null ? self::child($info, 'Totals') : null;
        $body = self::child($boq, 'BoQBody');
        [$categories, $items] = $body !== null ? self::parseBody($body, []) : [[], []];

        return new BoQ(
            label: $info !== null ? self::text($info, 'LblBoQ') : null,
            currency: $info !== null ? self::text($info, 'Cur') : null,
            total: $totals !== null ? self::floatVal($totals, 'Total') : null,
            categories: $categories,
            items: $items,
        );
    }

    /**
     * @param  list<string>  $prefix
     * @return array{list<BoQCategory>, list<Item>}
     */
    private static function parseBody(\DOMElement $body, array $prefix): array
    {
        $categories = [];
        foreach (self::children($body, 'BoQCtgy') as $ctgy) {
            $categories[] = self::parseCategory($ctgy, $prefix);
        }

        $items = [];
        foreach (self::children($body, 'Itemlist') as $list) {
            foreach (self::children($list, 'Item') as $item) {
                $items[] = self::parseItem($item, $prefix);
            }
        }

        return [$categories, $items];
    }

    private static function parseCategory(\DOMElement $ctgy, array $prefix): BoQCategory
    {
        $rNoPart = $ctgy->getAttribute('RNoPart');
        $body = self::child($ctgy, 'BoQBody');
        [$categories, $items] = $body !== null
            ? self::parseBody($body, [...$prefix, $rNoPart])
            : [[], []];

        return new BoQCategory(
            rNoPart: $rNoPart,
            label: self::flatten(self::child($ctgy, 'LblTx')),
            categories: $categories,
            items: $items,
        );
    }

    /** @param list<string> $prefix */
    private static function parseItem(\DOMElement $item, array $prefix): Item
    {
        $rNoPart = $item->getAttribute('RNoPart');
        $description = self::child($item, 'Description');
        $complete = $description !== null ? self::child($description, 'CompleteText') : null;

        $shortText = null;
        $longText = null;
        if ($complete !== null) {
            $outline = $complete->getElementsByTagNameNS('*', 'TextOutlTxt');
            $shortText = $outline->length > 0 ? self::flatten($outline->item(0)) : null;
            $detail = $complete->getElementsByTagNameNS('*', 'DetailTxt');
            $longText = $detail->length > 0 ? self::flatten($detail->item(0)) : null;
        }

        return new Item(
            rNo: implode('.', [...$prefix, $rNoPart]),
            rNoPart: $rNoPart,
            qty: self::floatVal($item, 'Qty'),
            unit: self::text($item, 'QU'),
            shortText: $shortText,
            longText: $longText,
            descriptionXml: $description?->ownerDocument?->saveXML($description) ?: null,
            unitPrice: self::floatVal($item, 'UP'),
            totalPrice: self::floatVal($item, 'IT'),
            lumpSum: self::text($item, 'LumpSumItem') === 'Yes',
        );
    }
```

Add the missing `@param list<string> $prefix` docblock on `parseCategory` if phpstan asks.

- [ ] **Step 6: Run tests to verify they pass**

Run: `vendor/bin/pest`
Expected: all tests PASS (Task 2 tests must still pass — the `GaebFile` constructor gained a required `$boq` param, `fromString` now supplies it).

- [ ] **Step 7: Lint, analyse, commit**

Run: `composer lint && composer analyse && composer test`

```bash
git add src tests
git commit -m "feat: parse BoQ category tree and items"
```

---

### Task 4: Prices, totals, flattened iteration

**Files:**
- Create: `tests/fixtures/priced.x84`
- Test: `tests/PricedBoQTest.php`

**Interfaces:**
- Consumes: everything from Task 3 (`Item->unitPrice/totalPrice`, `BoQ->total`, `BoQ::allItems()` are already implemented — this task proves them against a priced X84 fixture)
- Produces: verified behavior later tasks and consumers rely on; no new code expected (only fixes if tests reveal gaps)

- [ ] **Step 1: Write fixture `tests/fixtures/priced.x84`**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA84/3.3">
  <GAEBInfo><Version>3.3</Version></GAEBInfo>
  <PrjInfo><Name>PRJ-1</Name><Cur>EUR</Cur></PrjInfo>
  <Award>
    <DP>84</DP>
    <BoQ>
      <BoQInfo>
        <LblBoQ>Angebot Testhalle</LblBoQ>
        <Cur>EUR</Cur>
        <Totals><Total>1450.00</Total></Totals>
      </BoQInfo>
      <BoQBody>
        <BoQCtgy RNoPart="01">
          <LblTx><p><span>Erdarbeiten</span></p></LblTx>
          <BoQBody>
            <Itemlist>
              <Item RNoPart="0010">
                <Qty>100.000</Qty>
                <QU>m3</QU>
                <Description>
                  <CompleteText>
                    <OutlineText>
                      <OutlTxt><TextOutlTxt><p><span>Boden loesen</span></p></TextOutlTxt></OutlTxt>
                    </OutlineText>
                  </CompleteText>
                </Description>
                <UP>12.50</UP>
                <IT>1250.00</IT>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQCtgy>
        <Itemlist>
          <Item RNoPart="90">
            <QU>psch</QU>
            <Description>
              <CompleteText>
                <OutlineText>
                  <OutlTxt><TextOutlTxt><p><span>Stundenlohnarbeiten</span></p></TextOutlTxt></OutlTxt>
                </OutlineText>
              </CompleteText>
            </Description>
            <UP>200.00</UP>
            <IT>200.00</IT>
          </Item>
        </Itemlist>
      </BoQBody>
    </BoQ>
  </Award>
</GAEB>
```

(The top-level `Itemlist` next to `BoQCtgy` deliberately exercises items living directly on the BoQ body.)

- [ ] **Step 2: Write the failing tests**

`tests/PricedBoQTest.php`:
```php
<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('parses prices and totals from an x84 file', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/priced.x84');

    expect($gaeb->info->phase)->toBe(84)
        ->and($gaeb->boq->total)->toBe(1450.00)
        ->and($gaeb->boq->items)->toHaveCount(1)
        ->and($gaeb->boq->items[0]->rNo)->toBe('90');

    $item = $gaeb->boq->categories[0]->items[0];
    expect($item->unitPrice)->toBe(12.50)
        ->and($item->totalPrice)->toBe(1250.00);
});

it('iterates all items flattened with resolved position numbers', function () {
    $items = iterator_to_array(
        GaebParser::fromFile(__DIR__.'/fixtures/priced.x84')->boq->allItems(),
        false,
    );

    expect($items)->toHaveCount(2)
        ->and(array_map(fn ($i) => $i->rNo, $items))->toBe(['90', '01.0010']);
});
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/pest tests/PricedBoQTest.php`
Expected: PASS immediately if Task 3 was implemented correctly (this task is verification of already-written code against a new phase). If anything FAILS, fix `GaebParser` — do not adjust assertions to match wrong behavior.

- [ ] **Step 4: Lint, analyse, commit**

Run: `composer lint && composer analyse && composer test`

```bash
git add tests
git commit -m "test: cover priced x84 files and flattened item iteration"
```

---

### Task 5: Real-world sample fixture, README, final QA

**Files:**
- Create: `README.md`, `LICENSE.md`, optionally `tests/fixtures/sample-*.x8*` + `tests/RealWorldSampleTest.php`

**Interfaces:**
- Consumes: full public API from Tasks 2–4
- Produces: shippable package

- [ ] **Step 1: Try to obtain a public GAEB 3.3 sample file**

Search GitHub code search for real GAEB 3.3 files, e.g. query `"gaeb.de/GAEB_DA_XML/DA83/3.3" path:*.x83` (also try `.x84`, `.x86`, and permissively-licensed repos of GAEB tooling in Java/C#/Python which often ship samples). Verify the repo license permits redistribution (MIT/Apache/BSD). If found: download to `tests/fixtures/`, note origin + license in a `tests/fixtures/README.md`.

If no redistributable sample is found within reasonable effort: **skip** this fixture, and note in the main README that test fixtures are synthetic. Do not burn time here.

- [ ] **Step 2: If a sample was found, write a smoke test**

`tests/RealWorldSampleTest.php` (adjust filename/assertions to the actual sample — assert only invariants, not guessed values):
```php
<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('parses a real-world GAEB sample', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/sample.x83');

    expect($gaeb->info->phase)->toBeIn([81, 82, 83, 84, 85, 86])
        ->and($gaeb->boq)->not->toBeNull()
        ->and(iterator_to_array($gaeb->boq->allItems(), false))->not->toBeEmpty();

    foreach ($gaeb->boq->allItems() as $item) {
        expect($item->rNo)->not->toBe('');
    }
});
```

Run: `vendor/bin/pest` — all PASS.

- [ ] **Step 3: Write README.md**

Cover: what it is (GAEB DA XML 3.3, phases X81–X86, read-only, lenient), install (`composer require bambamboole/gaeb-parser`), the usage example from the spec (fromFile/fromString, info/project/boq, allItems loop with property list), what is out of scope (writing, XSD validation, GAEB 90/2000), MIT license. Copy the usage example verbatim from `docs/superpowers/specs/2026-07-31-gaeb-parser-design.md`.

- [ ] **Step 4: Write LICENSE.md**

MIT license text, copyright `Manuel Christlieb`.

- [ ] **Step 5: Final QA**

Run: `composer test:lint && composer analyse && composer test`
Expected: everything green.

- [ ] **Step 6: Commit**

```bash
git add README.md LICENSE.md tests
git commit -m "docs: add readme, license and real-world sample coverage"
```
