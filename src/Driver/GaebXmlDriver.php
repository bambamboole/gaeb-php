<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Driver;

use Bambamboole\Gaeb\Dto\AwardData;
use Bambamboole\Gaeb\Dto\BoQ;
use Bambamboole\Gaeb\Dto\BoQCategory;
use Bambamboole\Gaeb\Dto\ChangeOrder;
use Bambamboole\Gaeb\Dto\ChangeOrderStatus;
use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\Dto\GaebInfo;
use Bambamboole\Gaeb\Dto\InvoiceData;
use Bambamboole\Gaeb\Dto\InvoiceType;
use Bambamboole\Gaeb\Dto\Item;
use Bambamboole\Gaeb\Dto\Party;
use Bambamboole\Gaeb\Dto\Payment;
use Bambamboole\Gaeb\Dto\ProjectInfo;
use Bambamboole\Gaeb\Dto\Provisional;
use Bambamboole\Gaeb\Dto\SettlementType;
use Bambamboole\Gaeb\Dto\SubDescription;
use Bambamboole\Gaeb\Dto\TextComplement;
use Bambamboole\Gaeb\Dto\TextComplementKind;
use Bambamboole\Gaeb\Dto\Totals;
use Bambamboole\Gaeb\Dto\WarrantyUnit;
use Bambamboole\Gaeb\GaebParseException;
use Bambamboole\Gaeb\Xml\Dom;
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
        $container = $award ?? Dom::child($root, 'Invoice');

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root, $container),
            boq: $container !== null ? self::parseBoQ($container) : null,
            owner: $container !== null ? self::parseParty(Dom::child($container, 'OWN')) : null,
            contractor: $container !== null ? self::parseParty(Dom::child($container, 'CTR')) : null,
            award: $container !== null ? self::parseAwardData(Dom::child($container, 'AwardInfo')) : null,
            invoice: $container !== null ? self::parseInvoiceData($container) : null,
        );
    }

    private static function parseInfo(Element $root): GaebInfo
    {
        $info = Dom::child($root, 'GAEBInfo');
        $container = Dom::child($root, 'Award') ?? Dom::child($root, 'Invoice');

        $phase = null;
        $dp = $container !== null ? Dom::text($container, 'DP') : null;
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

    private static function parseProject(Element $root, ?Element $container): ProjectInfo
    {
        $prj = Dom::child($root, 'PrjInfo');
        $awardInfo = $container !== null ? Dom::child($container, 'AwardInfo') : null;

        return new ProjectInfo(
            name: $prj !== null ? (Dom::text($prj, 'NamePrj') ?? Dom::text($prj, 'Name')) : null,
            label: $prj !== null ? Dom::text($prj, 'LblPrj') : null,
            currency: ($prj !== null ? Dom::text($prj, 'Cur') : null)
                ?? ($awardInfo !== null ? Dom::text($awardInfo, 'Cur') : null),
        );
    }

    private static function parseBoQ(Element $container): ?BoQ
    {
        $boq = Dom::child($container, 'BoQ');
        if ($boq === null) {
            return null;
        }

        $info = Dom::child($boq, 'BoQInfo');
        $awardInfo = Dom::child($container, 'AwardInfo');
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
            changeOrderNo: $info !== null ? Dom::intVal($info, 'CONo') : null,
            changeOrderStatus: $info !== null ? self::parseChangeOrderStatus($info) : null,
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
            changeOrderNo: Dom::intVal($ctgy, 'CONo'),
            changeOrderStatus: self::parseChangeOrderStatus($ctgy),
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
            billedQty: Dom::floatVal($item, 'BillQty'),
            changeOrderNo: Dom::intVal($item, 'CONo'),
            changeOrderStatus: self::parseChangeOrderStatus($item),
        );
    }

    private static function parseChangeOrderStatus(Element $parent): ?ChangeOrderStatus
    {
        return ChangeOrderStatus::tryFrom((string) Dom::text($parent, 'COStatus'));
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
            taxNo: Dom::text($party, 'TaxNo'),
        );
    }

    private static function parseAwardData(?Element $awardInfo): ?AwardData
    {
        if ($awardInfo === null) {
            return null;
        }

        $changeOrders = [];
        foreach (Dom::children($awardInfo, 'COInfo') as $info) {
            $changeOrders[] = new ChangeOrder(
                no: Dom::intVal($info, 'CONo'),
                phase: Dom::text($info, 'COPhase'),
                status: self::parseChangeOrderStatus($info),
                initiator: Dom::text($info, 'COInit'),
                reason: Dom::flatten(Dom::child($info, 'COReas')),
                reference: Dom::text($info, 'RefBoQCOInfo'),
                date: Dom::text($info, 'CODate'),
            );
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
            changeOrders: $changeOrders,
        );
    }

    private static function parseInvoiceData(Element $container): ?InvoiceData
    {
        $header = Dom::child($container, 'InvoiceHeader');
        if ($header === null) {
            return null;
        }

        $payments = [];
        foreach (Dom::children($container, 'PaymentMade') as $payment) {
            $payments[] = new Payment(
                total: Dom::text($payment, 'Total'),
                totalVat: Dom::text($payment, 'TotalVAT'),
                discountAmount: Dom::text($payment, 'DiscountAmt'),
                paymentDate: Dom::text($payment, 'PaymentDate'),
                invoiceNo: Dom::text($payment, 'InvoiceNo'),
                paymentNo: Dom::text($payment, 'PaymentNo'),
                paymentNote: Dom::text($payment, 'PaymentNote'),
            );
        }

        return new InvoiceData(
            invoiceNo: Dom::text($header, 'InvoiceNo'),
            invoiceDate: Dom::text($header, 'InvoiceDate'),
            type: InvoiceType::tryFrom((string) Dom::text($header, 'InvoiceType')),
            creditNote: Dom::text($header, 'CreditNote') === 'Yes',
            settlementType: SettlementType::tryFrom((string) Dom::text($header, 'SettlementType')),
            sequentialNo: Dom::intVal($header, 'SequentialNo'),
            servicePeriodStart: Dom::text($header, 'ServiceProvisionStartDate'),
            servicePeriodEnd: Dom::text($header, 'ServiceProvisionEndDate'),
            creator: self::parseParty(Dom::child($container, 'InvoiceCreator')),
            recipient: self::parseParty(Dom::child($container, 'InvoiceRecipient')),
            payments: $payments,
            totalGross: Dom::floatVal($container, 'TotalGross'),
        );
    }
}
