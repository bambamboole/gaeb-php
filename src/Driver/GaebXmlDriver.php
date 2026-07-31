<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Driver;

use Bambamboole\GaebParser\Dto\AwardData;
use Bambamboole\GaebParser\Dto\BoQ;
use Bambamboole\GaebParser\Dto\BoQCategory;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\Item;
use Bambamboole\GaebParser\Dto\Party;
use Bambamboole\GaebParser\Dto\ProjectInfo;
use Bambamboole\GaebParser\Dto\Provisional;
use Bambamboole\GaebParser\Dto\SubDescription;
use Bambamboole\GaebParser\Dto\TextComplement;
use Bambamboole\GaebParser\Dto\TextComplementKind;
use Bambamboole\GaebParser\Dto\Totals;
use Bambamboole\GaebParser\Dto\WarrantyUnit;
use Bambamboole\GaebParser\GaebParseException;
use Bambamboole\GaebParser\Xml\Dom;
use Dom\Element;
use Dom\XMLDocument;

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
        try {
            $doc = Dom::parse($content);
        } catch (\DOMException|\ValueError) {
            throw new GaebParseException('Invalid XML');
        }

        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        $award = Dom::child($root, 'Award');

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root, $award),
            boq: $award !== null ? self::parseBoQ($award) : null,
            owner: $award !== null ? self::parseParty(Dom::child($award, 'OWN')) : null,
            contractor: $award !== null ? self::parseParty(Dom::child($award, 'CTR')) : null,
            award: $award !== null ? self::parseAwardData(Dom::child($award, 'AwardInfo')) : null,
        );
    }

    private static function parseInfo(Element $root): GaebInfo
    {
        $info = Dom::child($root, 'GAEBInfo');
        $award = Dom::child($root, 'Award');

        $phase = null;
        $dp = $award !== null ? Dom::text($award, 'DP') : null;
        if ($dp !== null && ctype_digit($dp)) {
            $phase = (int) $dp;
        } elseif (preg_match('~/DA(8\d)/~', (string) $root->namespaceURI, $m) === 1) {
            $phase = (int) $m[1];
        }

        return new GaebInfo(
            version: $info !== null ? Dom::text($info, 'Version') : null,
            phase: $phase,
            date: $info !== null ? Dom::text($info, 'Date') : null,
            program: $info !== null ? (Dom::text($info, 'ProgSystem') ?? Dom::text($info, 'ProgName')) : null,
        );
    }

    private static function parseProject(Element $root, ?Element $award): ProjectInfo
    {
        $prj = Dom::child($root, 'PrjInfo');
        $awardInfo = $award !== null ? Dom::child($award, 'AwardInfo') : null;

        return new ProjectInfo(
            name: $prj !== null ? (Dom::text($prj, 'NamePrj') ?? Dom::text($prj, 'Name')) : null,
            label: $prj !== null ? Dom::text($prj, 'LblPrj') : null,
            currency: ($prj !== null ? Dom::text($prj, 'Cur') : null)
                ?? ($awardInfo !== null ? Dom::text($awardInfo, 'Cur') : null),
        );
    }

    private static function parseBoQ(Element $award): ?BoQ
    {
        $boq = Dom::child($award, 'BoQ');
        if ($boq === null) {
            return null;
        }

        $info = Dom::child($boq, 'BoQInfo');
        $awardInfo = Dom::child($award, 'AwardInfo');
        $totals = $info !== null ? Dom::child($info, 'Totals') : null;
        $body = Dom::child($boq, 'BoQBody');
        [$categories, $items] = $body !== null ? self::parseBody($body, []) : [[], []];

        $totalsDto = $totals !== null ? new Totals(
            total: Dom::floatVal($totals, 'Total'),
            discountPercent: Dom::floatVal($totals, 'DiscountPcnt'),
            discountAmount: Dom::floatVal($totals, 'DiscountAmt'),
            totalAfterDiscount: Dom::floatVal($totals, 'TotAfterDisc'),
            vat: Dom::floatVal($totals, 'VAT'),
            vatAmount: Dom::floatVal($totals, 'VATAmount'),
            totalNet: Dom::floatVal($totals, 'TotalNet'),
            totalGross: Dom::floatVal($totals, 'TotalGross'),
        ) : null;

        return new BoQ(
            label: $info !== null ? (Dom::text($info, 'LblBoQ') ?? Dom::text($info, 'Name')) : null,
            currency: ($info !== null ? Dom::text($info, 'Cur') : null)
                ?? ($awardInfo !== null ? Dom::text($awardInfo, 'Cur') : null),
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
    private static function parseBody(Element $body, array $prefix): array
    {
        $categories = [];
        foreach (Dom::children($body, 'BoQCtgy') as $ctgy) {
            $categories[] = self::parseCategory($ctgy, $prefix);
        }

        $items = [];
        foreach (Dom::children($body, 'Itemlist') as $list) {
            foreach (Dom::children($list, 'Item') as $item) {
                $items[] = self::parseItem($item, $prefix);
            }
        }

        return [$categories, $items];
    }

    /**
     * @param  list<string>  $prefix
     */
    private static function parseCategory(Element $ctgy, array $prefix): BoQCategory
    {
        $rNoPart = Dom::attr($ctgy, 'RNoPart');
        $body = Dom::child($ctgy, 'BoQBody');
        [$categories, $items] = $body !== null
            ? self::parseBody($body, [...$prefix, $rNoPart])
            : [[], []];

        return new BoQCategory(
            rNoPart: $rNoPart,
            label: Dom::flatten(Dom::child($ctgy, 'LblTx')),
            categories: $categories,
            items: $items,
        );
    }

    /** @param list<string> $prefix */
    private static function parseItem(Element $item, array $prefix): Item
    {
        $rNoPart = Dom::attr($item, 'RNoPart');
        $rNoIndex = Dom::attr($item, 'RNoIndex');
        $rNoSegment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
        $description = Dom::child($item, 'Description');
        [$shortText, $longText, $descriptionXml] = self::extractDescriptionTexts($description);

        return new Item(
            rNo: implode('.', [...$prefix, $rNoSegment]),
            rNoPart: $rNoPart,
            qty: Dom::floatVal($item, 'Qty'),
            unit: Dom::text($item, 'QU'),
            shortText: $shortText,
            longText: $longText,
            descriptionXml: $descriptionXml,
            unitPrice: Dom::floatVal($item, 'UP'),
            totalPrice: Dom::floatVal($item, 'IT'),
            lumpSum: Dom::text($item, 'LumpSumItem') === 'Yes',
            provisional: Provisional::tryFrom((string) Dom::text($item, 'Provis')),
            hourlyWork: Dom::text($item, 'HourIt') === 'Yes',
            notApplicable: Dom::text($item, 'NotAppl') === 'Yes',
            alternativeGroupNo: Dom::intVal($item, 'ALNGroupNo'),
            alternativeSerialNo: Dom::intVal($item, 'ALNSerNo'),
            textComplements: self::parseTextComplements($description),
            bidderComment: self::parseBidderComment($item),
            subDescriptions: self::parseSubDescriptions($item),
        );
    }

    /** @return array{?string, ?string, ?string} shortText, longText, descriptionXml */
    private static function extractDescriptionTexts(?Element $description): array
    {
        if ($description === null) {
            return [null, null, null];
        }
        $complete = Dom::child($description, 'CompleteText');
        $shortText = null;
        $longText = null;
        if ($complete !== null) {
            $outline = $complete->querySelectorAll('TextOutlTxt');
            $shortText = $outline->length > 0 ? Dom::flatten($outline->item(0)) : null;
            $detail = $complete->querySelectorAll('DetailTxt');
            $longText = $detail->length > 0 ? Dom::flatten($detail->item(0)) : null;
        }

        $owner = $description->ownerDocument;
        $descriptionXml = $owner instanceof XMLDocument ? ($owner->saveXml($description) ?: null) : null;

        return [$shortText, $longText, $descriptionXml];
    }

    /** @return list<TextComplement> */
    private static function parseTextComplements(?Element $description): array
    {
        if ($description === null) {
            return [];
        }
        $complements = [];
        foreach ($description->querySelectorAll('TextComplement') as $node) {
            $kind = TextComplementKind::tryFrom(Dom::attr($node, 'Kind'));
            if ($kind === null) {
                continue;
            }
            $markLabel = Dom::attr($node, 'MarkLbl');
            $complements[] = new TextComplement(
                markLabel: ctype_digit($markLabel) ? (int) $markLabel : 0,
                kind: $kind,
                caption: Dom::flatten(Dom::child($node, 'ComplCaption')),
                body: Dom::flatten(Dom::child($node, 'ComplBody')),
                tail: Dom::flatten(Dom::child($node, 'ComplTail')),
            );
        }

        return $complements;
    }

    private static function parseBidderComment(Element $item): ?string
    {
        $comments = [];
        foreach (Dom::children($item, 'BidComm') as $comm) {
            $flattened = Dom::flatten($comm);
            if ($flattened !== null) {
                $comments[] = $flattened;
            }
        }

        return $comments === [] ? null : implode("\n", $comments);
    }

    /** @return list<SubDescription> */
    private static function parseSubDescriptions(Element $item): array
    {
        $subs = [];
        foreach (Dom::children($item, 'SubDescr') as $sub) {
            [$shortText, $longText, $descriptionXml] = self::extractDescriptionTexts(Dom::child($sub, 'Description'));
            $subs[] = new SubDescription(
                subDNo: Dom::text($sub, 'SubDNo'),
                shortText: $shortText,
                longText: $longText,
                descriptionXml: $descriptionXml,
                qty: Dom::floatVal($sub, 'Qty'),
                unit: Dom::text($sub, 'QU'),
                unitPrice: Dom::floatVal($sub, 'UP'),
            );
        }

        return $subs;
    }

    private static function parseParty(?Element $party): ?Party
    {
        if ($party === null) {
            return null;
        }
        $address = Dom::child($party, 'Address');

        return new Party(
            name: $address !== null ? Dom::text($address, 'Name1') : null,
            street: $address !== null ? Dom::text($address, 'Street') : null,
            zip: $address !== null ? Dom::text($address, 'PCode') : null,
            city: $address !== null ? Dom::text($address, 'City') : null,
            phone: $address !== null ? Dom::text($address, 'Phone') : null,
            email: $address !== null ? Dom::text($address, 'Email') : null,
        );
    }

    private static function parseAwardData(?Element $awardInfo): ?AwardData
    {
        if ($awardInfo === null) {
            return null;
        }

        return new AwardData(
            contractNo: Dom::text($awardInfo, 'ContrNo'),
            contractDate: Dom::text($awardInfo, 'ContrDate'),
            bidDate: Dom::text($awardInfo, 'BidDate'),
            constructionStart: Dom::text($awardInfo, 'CnstStart'),
            constructionEnd: Dom::text($awardInfo, 'CnstEnd'),
            warrantyDuration: Dom::intVal($awardInfo, 'WarrDur'),
            warrantyUnit: WarrantyUnit::tryFrom((string) Dom::text($awardInfo, 'WarrUnit')),
            warrantyEnd: Dom::text($awardInfo, 'WarrEnd'),
        );
    }
}
