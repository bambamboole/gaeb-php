<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Dto\BoQ;
use Bambamboole\GaebParser\Dto\BoQCategory;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\Item;
use Bambamboole\GaebParser\Dto\ProjectInfo;

final class GaebParser
{
    public static function fromFile(string $path): GaebFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        $xml = file_get_contents($path);
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

        $award = self::child($root, 'Award');

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root),
            boq: $award !== null ? self::parseBoQ($award) : null,
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

    /**
     * @param  list<string>  $prefix
     */
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

    private static function child(\DOMElement $el, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
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

    private static function floatVal(\DOMElement $el, string $name): ?float
    {
        $value = self::text($el, $name);

        return $value === null ? null : (float) $value;
    }

    /** Flatten GAEB rich text (<p><span>…) to plain text, paragraphs joined by
. */
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

        return $lines === [] ? null : implode('
', $lines);
    }
}
