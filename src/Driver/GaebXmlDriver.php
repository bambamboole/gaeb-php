<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Driver;

use Bambamboole\GaebParser\Dto\BoQ;
use Bambamboole\GaebParser\Dto\BoQCategory;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\Item;
use Bambamboole\GaebParser\Dto\ProjectInfo;
use Bambamboole\GaebParser\Dto\Provisional;
use Bambamboole\GaebParser\Dto\SubDescription;
use Bambamboole\GaebParser\Dto\TextComplement;
use Bambamboole\GaebParser\Dto\TextComplementKind;
use Bambamboole\GaebParser\Dto\Totals;
use Bambamboole\GaebParser\GaebParseException;

final class GaebXmlDriver implements Driver
{
    public function supports(string $content): bool
    {
        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return true;
        }

        return str_starts_with(ltrim($content, "\xEF\xBB\xBF \t\r\n"), '<');
    }

    public function parse(string $content): GaebFile
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($content);
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
            project: self::parseProject($root, $award),
            boq: $award !== null ? self::parseBoQ($award) : null,
        );
    }

    private static function parseInfo(\DOMElement $root): GaebInfo
    {
        $info = self::child($root, 'GAEBInfo');
        $award = self::child($root, 'Award');

        $phase = null;
        $dp = $award !== null ? self::text($award, 'DP') : null;
        if ($dp !== null && ctype_digit($dp)) {
            $phase = (int) $dp;
        } elseif (preg_match('~/DA(8\d)/~', (string) $root->namespaceURI, $m) === 1) {
            $phase = (int) $m[1];
        }

        return new GaebInfo(
            version: $info !== null ? self::text($info, 'Version') : null,
            phase: $phase,
            date: $info !== null ? self::text($info, 'Date') : null,
            program: $info !== null ? (self::text($info, 'ProgSystem') ?? self::text($info, 'ProgName')) : null,
        );
    }

    private static function parseProject(\DOMElement $root, ?\DOMElement $award): ProjectInfo
    {
        $prj = self::child($root, 'PrjInfo');
        $awardInfo = $award !== null ? self::child($award, 'AwardInfo') : null;

        return new ProjectInfo(
            name: $prj !== null ? (self::text($prj, 'NamePrj') ?? self::text($prj, 'Name')) : null,
            label: $prj !== null ? self::text($prj, 'LblPrj') : null,
            currency: ($prj !== null ? self::text($prj, 'Cur') : null)
                ?? ($awardInfo !== null ? self::text($awardInfo, 'Cur') : null),
        );
    }

    private static function parseBoQ(\DOMElement $award): ?BoQ
    {
        $boq = self::child($award, 'BoQ');
        if ($boq === null) {
            return null;
        }

        $info = self::child($boq, 'BoQInfo');
        $awardInfo = self::child($award, 'AwardInfo');
        $totals = $info !== null ? self::child($info, 'Totals') : null;
        $body = self::child($boq, 'BoQBody');
        [$categories, $items] = $body !== null ? self::parseBody($body, []) : [[], []];

        $totalsDto = $totals !== null ? new Totals(
            total: self::floatVal($totals, 'Total'),
            discountPercent: self::floatVal($totals, 'DiscountPcnt'),
            discountAmount: self::floatVal($totals, 'DiscountAmt'),
            totalAfterDiscount: self::floatVal($totals, 'TotAfterDisc'),
            vat: self::floatVal($totals, 'VAT'),
            vatAmount: self::floatVal($totals, 'VATAmount'),
            totalNet: self::floatVal($totals, 'TotalNet'),
            totalGross: self::floatVal($totals, 'TotalGross'),
        ) : null;

        return new BoQ(
            label: $info !== null ? (self::text($info, 'LblBoQ') ?? self::text($info, 'Name')) : null,
            currency: ($info !== null ? self::text($info, 'Cur') : null)
                ?? ($awardInfo !== null ? self::text($awardInfo, 'Cur') : null),
            total: $totalsDto?->total,
            totals: $totalsDto,
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
        $rNoIndex = $item->getAttribute('RNoIndex');
        $rNoSegment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
        $description = self::child($item, 'Description');
        [$shortText, $longText, $descriptionXml] = self::extractDescriptionTexts($description);

        return new Item(
            rNo: implode('.', [...$prefix, $rNoSegment]),
            rNoPart: $rNoPart,
            qty: self::floatVal($item, 'Qty'),
            unit: self::text($item, 'QU'),
            shortText: $shortText,
            longText: $longText,
            descriptionXml: $descriptionXml,
            unitPrice: self::floatVal($item, 'UP'),
            totalPrice: self::floatVal($item, 'IT'),
            lumpSum: self::text($item, 'LumpSumItem') === 'Yes',
            provisional: Provisional::tryFrom((string) self::text($item, 'Provis')),
            hourlyWork: self::text($item, 'HourIt') === 'Yes',
            notApplicable: self::text($item, 'NotAppl') === 'Yes',
            alternativeGroupNo: self::intVal($item, 'ALNGroupNo'),
            alternativeSerialNo: self::intVal($item, 'ALNSerNo'),
            textComplements: self::parseTextComplements($description),
            bidderComment: self::parseBidderComment($item),
            subDescriptions: self::parseSubDescriptions($item),
        );
    }

    /** @return array{?string, ?string, ?string} shortText, longText, descriptionXml */
    private static function extractDescriptionTexts(?\DOMElement $description): array
    {
        if ($description === null) {
            return [null, null, null];
        }
        $complete = self::child($description, 'CompleteText');
        $shortText = null;
        $longText = null;
        if ($complete !== null) {
            $outline = $complete->getElementsByTagNameNS('*', 'TextOutlTxt');
            $shortText = $outline->length > 0 ? self::flatten($outline->item(0)) : null;
            $detail = $complete->getElementsByTagNameNS('*', 'DetailTxt');
            $longText = $detail->length > 0 ? self::flatten($detail->item(0)) : null;
        }

        return [$shortText, $longText, $description->ownerDocument?->saveXML($description) ?: null];
    }

    /** @return list<TextComplement> */
    private static function parseTextComplements(?\DOMElement $description): array
    {
        if ($description === null) {
            return [];
        }
        $complements = [];
        foreach ($description->getElementsByTagNameNS('*', 'TextComplement') as $node) {
            $kind = TextComplementKind::tryFrom($node->getAttribute('Kind'));
            if ($kind === null) {
                continue;
            }
            $markLabel = $node->getAttribute('MarkLbl');
            $complements[] = new TextComplement(
                markLabel: ctype_digit($markLabel) ? (int) $markLabel : 0,
                kind: $kind,
                caption: self::flatten(self::child($node, 'ComplCaption')),
                body: self::flatten(self::child($node, 'ComplBody')),
                tail: self::flatten(self::child($node, 'ComplTail')),
            );
        }

        return $complements;
    }

    private static function parseBidderComment(\DOMElement $item): ?string
    {
        $comments = [];
        foreach (self::children($item, 'BidComm') as $comm) {
            $flattened = self::flatten($comm);
            if ($flattened !== null) {
                $comments[] = $flattened;
            }
        }

        return $comments === [] ? null : implode("\n", $comments);
    }

    /** @return list<SubDescription> */
    private static function parseSubDescriptions(\DOMElement $item): array
    {
        $subs = [];
        foreach (self::children($item, 'SubDescr') as $sub) {
            [$shortText, $longText, $descriptionXml] = self::extractDescriptionTexts(self::child($sub, 'Description'));
            $subs[] = new SubDescription(
                subDNo: self::text($sub, 'SubDNo'),
                shortText: $shortText,
                longText: $longText,
                descriptionXml: $descriptionXml,
                qty: self::floatVal($sub, 'Qty'),
                unit: self::text($sub, 'QU'),
                unitPrice: self::floatVal($sub, 'UP'),
            );
        }

        return $subs;
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

    private static function intVal(\DOMElement $el, string $name): ?int
    {
        $value = self::text($el, $name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Flatten GAEB rich text (<p><span>…) to plain text, paragraphs joined by newlines. */
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
