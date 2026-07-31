<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Write;

use Bambamboole\GaebParser\Dto\Contractor;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\Item;
use Bambamboole\GaebParser\Dto\Provisional;
use Bambamboole\GaebParser\GaebWriteException;
use Bambamboole\GaebParser\Xml\Dom;

/**
 * @internal builds the X84 bid DOM from a source X81/X83 DOM plus its parsed
 * read model and a Bid collector. Kept out of GaebDocument to keep that
 * class thin; see docs/superpowers/specs/2026-07-31-write-foundation-x84-bid-design.md
 * for the emission rules this implements.
 */
final class BidWriter
{
    private const NS = 'http://www.gaeb.de/GAEB_DA_XML/DA84/3.3';

    /**
     * Fixed, non-wall-clock GAEBInfo/Date. The X84 schema requires Date
     * (unlike the design sketch's assumption that it could be omitted
     * entirely); a literal keeps output deterministic across runs.
     */
    private const FIXED_DATE = '2021-05-01';

    public function write(\DOMDocument $source, GaebFile $file, Bid $bid): \DOMDocument
    {
        /** @var array<string, Item> $itemsByRNo */
        $itemsByRNo = [];
        foreach ($file->boq?->allItems() ?? [] as $item) {
            $itemsByRNo[$item->rNo] = $item;
        }

        $this->assertPricesComplete($itemsByRNo, $bid);
        $this->assertKnownRNos($itemsByRNo, $bid);

        $out = new \DOMDocument('1.0', 'UTF-8');
        $out->formatOutput = true;

        $root = $out->createElementNS(self::NS, 'GAEB');
        $out->appendChild($root);

        $root->appendChild($this->buildGaebInfo($out));
        $root->appendChild($this->buildPrjInfo($out, $file));
        $root->appendChild($this->buildAward($out, $source, $file, $bid, $itemsByRNo));

        return $out;
    }

    /** @param array<string, Item> $itemsByRNo */
    private function assertPricesComplete(array $itemsByRNo, Bid $bid): void
    {
        $missing = [];
        foreach ($itemsByRNo as $rNo => $item) {
            if (! $item->notApplicable && ! array_key_exists($rNo, $bid->prices())) {
                $missing[] = $rNo;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException('Missing unit price for priceable item(s): '.implode(', ', $missing));
        }
    }

    /** @param array<string, Item> $itemsByRNo */
    private function assertKnownRNos(array $itemsByRNo, Bid $bid): void
    {
        $referenced = [
            ...array_keys($bid->prices()),
            ...array_keys($bid->gapFills()),
            ...array_keys($bid->comments()),
        ];
        $unknown = array_values(array_unique(array_diff($referenced, array_keys($itemsByRNo))));
        if ($unknown !== []) {
            throw new GaebWriteException('Unknown rNo(s) referenced in bid: '.implode(', ', $unknown));
        }
    }

    private function buildGaebInfo(\DOMDocument $out): \DOMElement
    {
        $info = $out->createElementNS(self::NS, 'GAEBInfo');
        $info->appendChild($out->createElementNS(self::NS, 'Version', '3.3'));
        $info->appendChild($out->createElementNS(self::NS, 'VersDate', '2021-05'));
        $info->appendChild($out->createElementNS(self::NS, 'Date', self::FIXED_DATE));
        $info->appendChild($out->createElementNS(self::NS, 'ProgSystem', 'bambamboole/gaeb-parser'));

        return $info;
    }

    private function buildPrjInfo(\DOMDocument $out, GaebFile $file): \DOMElement
    {
        $name = $file->project->name;
        if ($name === null) {
            throw new GaebWriteException('Source project has no name; cannot write PrjInfo/NamePrj.');
        }

        $prj = $out->createElementNS(self::NS, 'PrjInfo');
        $prj->appendChild($out->createElementNS(self::NS, 'NamePrj', $name));
        if ($file->project->label !== null) {
            $prj->appendChild($out->createElementNS(self::NS, 'LblPrj', $file->project->label));
        }

        return $prj;
    }

    /** @param array<string, Item> $itemsByRNo */
    private function buildAward(\DOMDocument $out, \DOMDocument $source, GaebFile $file, Bid $bid, array $itemsByRNo): \DOMElement
    {
        $award = $out->createElementNS(self::NS, 'Award');
        $award->appendChild($out->createElementNS(self::NS, 'DP', '84'));

        $currency = $bid->currency ?? $file->project->currency;
        if ($currency !== null) {
            $awardInfo = $out->createElementNS(self::NS, 'AwardInfo');
            $awardInfo->appendChild($out->createElementNS(self::NS, 'Cur', $currency));
            $award->appendChild($awardInfo);
        }

        $award->appendChild($this->buildCTR($out, $bid->contractor));
        $award->appendChild($this->buildBoQ($out, $source, $file, $bid, $itemsByRNo));

        return $award;
    }

    private function buildCTR(\DOMDocument $out, Contractor $contractor): \DOMElement
    {
        $missing = [];
        foreach (['name' => $contractor->name, 'street' => $contractor->street, 'zip' => $contractor->zip, 'city' => $contractor->city] as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException('Contractor is missing required field(s): '.implode(', ', $missing));
        }

        $ctr = $out->createElementNS(self::NS, 'CTR');
        $address = $out->createElementNS(self::NS, 'Address');
        $address->appendChild($out->createElementNS(self::NS, 'Name1', (string) $contractor->name));
        $address->appendChild($out->createElementNS(self::NS, 'Street', (string) $contractor->street));
        $address->appendChild($out->createElementNS(self::NS, 'PCode', (string) $contractor->zip));
        $address->appendChild($out->createElementNS(self::NS, 'City', (string) $contractor->city));
        if ($contractor->phone !== null) {
            $address->appendChild($out->createElementNS(self::NS, 'Phone', $contractor->phone));
        }
        if ($contractor->email !== null) {
            $address->appendChild($out->createElementNS(self::NS, 'Email', $contractor->email));
        }
        $ctr->appendChild($address);

        return $ctr;
    }

    /** @param array<string, Item> $itemsByRNo */
    private function buildBoQ(\DOMDocument $out, \DOMDocument $source, GaebFile $file, Bid $bid, array $itemsByRNo): \DOMElement
    {
        $srcRoot = $source->documentElement;
        $srcAward = $srcRoot !== null ? Dom::child($srcRoot, 'Award') : null;
        $srcBoQ = $srcAward !== null ? Dom::child($srcAward, 'BoQ') : null;
        if ($srcBoQ === null) {
            throw new GaebWriteException('Source document has no BoQ; cannot create a bid.');
        }

        $srcBoQInfo = Dom::child($srcBoQ, 'BoQInfo');
        if ($srcBoQInfo === null) {
            throw new GaebWriteException('Source BoQ has no BoQInfo; cannot create a bid.');
        }
        $name = Dom::text($srcBoQInfo, 'Name');
        if ($name === null) {
            throw new GaebWriteException('Source BoQ has no BoQInfo/Name; cannot create a bid.');
        }

        $srcBody = Dom::child($srcBoQ, 'BoQBody');
        [$bodyEl, $total] = $srcBody !== null
            ? $this->buildBoQBody($out, $srcBody, '', $bid, $itemsByRNo)
            : [null, 0.0];

        $boq = $out->createElementNS(self::NS, 'BoQ');
        $boq->setAttribute('ID', $srcBoQ->getAttribute('ID'));

        $boqInfo = $out->createElementNS(self::NS, 'BoQInfo');
        $boqInfo->appendChild($out->createElementNS(self::NS, 'Name', $name));
        foreach (Dom::children($srcBoQInfo, 'BoQBkdn') as $bkdn) {
            $boqInfo->appendChild($this->reNamespace($out, $bkdn));
        }
        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($out->createElementNS(self::NS, 'Total', number_format($total, 2, '.', '')));
        $boqInfo->appendChild($totalsEl);
        $boq->appendChild($boqInfo);

        if ($bodyEl !== null) {
            $boq->appendChild($bodyEl);
        }

        return $boq;
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return array{?\DOMElement, float}
     */
    private function buildBoQBody(\DOMDocument $out, \DOMElement $srcBody, string $prefix, Bid $bid, array $itemsByRNo): array
    {
        $bodyEl = null;
        $total = 0.0;

        foreach ($srcBody->childNodes as $node) {
            if (! $node instanceof \DOMElement) {
                continue;
            }
            if ($node->localName === 'BoQCtgy') {
                $built = $this->buildBoQCtgy($out, $node, $prefix, $bid, $itemsByRNo);
                if ($built === null) {
                    continue;
                }
                [$ctgyEl, $ctgyTotal] = $built;
                $bodyEl ??= $out->createElementNS(self::NS, 'BoQBody');
                $bodyEl->appendChild($ctgyEl);
                $total += $ctgyTotal;
            } elseif ($node->localName === 'Itemlist') {
                [$itemEls, $listTotal] = $this->buildItemlist($out, $node, $prefix, $bid, $itemsByRNo);
                if ($itemEls === []) {
                    continue;
                }
                $bodyEl ??= $out->createElementNS(self::NS, 'BoQBody');
                $listEl = $out->createElementNS(self::NS, 'Itemlist');
                foreach ($itemEls as $itemEl) {
                    $listEl->appendChild($itemEl);
                }
                $bodyEl->appendChild($listEl);
                $total += $listTotal;
            }
        }

        return [$bodyEl, $total];
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return ?array{\DOMElement, float}
     */
    private function buildBoQCtgy(\DOMDocument $out, \DOMElement $srcCtgy, string $prefix, Bid $bid, array $itemsByRNo): ?array
    {
        $rNoPart = $srcCtgy->getAttribute('RNoPart');
        $childPrefix = $prefix === '' ? $rNoPart : "{$prefix}.{$rNoPart}";

        $srcInnerBody = Dom::child($srcCtgy, 'BoQBody');
        [$bodyEl, $total] = $srcInnerBody !== null
            ? $this->buildBoQBody($out, $srcInnerBody, $childPrefix, $bid, $itemsByRNo)
            : [null, 0.0];

        if ($bodyEl === null) {
            // Every item under this category was notApplicable (or there
            // were none to begin with) — an empty BoQCtgy has nothing to
            // offer against, so it is dropped entirely rather than emitted
            // with a hollow Totals/Total 0.00.
            return null;
        }

        $ctgy = $out->createElementNS(self::NS, 'BoQCtgy');
        $ctgy->setAttribute('ID', $srcCtgy->getAttribute('ID'));
        $ctgy->setAttribute('RNoPart', $rNoPart);
        $ctgy->appendChild($bodyEl);

        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($out->createElementNS(self::NS, 'Total', number_format($total, 2, '.', '')));
        $ctgy->appendChild($totalsEl);

        return [$ctgy, $total];
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return array{list<\DOMElement>, float}
     */
    private function buildItemlist(\DOMDocument $out, \DOMElement $srcList, string $prefix, Bid $bid, array $itemsByRNo): array
    {
        $elements = [];
        $total = 0.0;

        foreach (Dom::children($srcList, 'Item') as $srcItem) {
            $rNoPart = $srcItem->getAttribute('RNoPart');
            $rNoIndex = $srcItem->getAttribute('RNoIndex');
            $segment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
            $rNo = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            $item = $itemsByRNo[$rNo] ?? null;
            if ($item === null || $item->notApplicable) {
                continue;
            }

            $up = $bid->prices()[$rNo];
            $it = $item->qty !== null ? round($item->qty * $up, 2) : round($up, 2);

            $itemEl = $out->createElementNS(self::NS, 'Item');
            $itemEl->setAttribute('ID', $srcItem->getAttribute('ID'));
            $itemEl->setAttribute('RNoPart', $rNoPart);
            if ($rNoIndex !== '') {
                $itemEl->setAttribute('RNoIndex', $rNoIndex);
            }
            if ($item->qty !== null) {
                $itemEl->appendChild($out->createElementNS(self::NS, 'Qty', number_format($item->qty, 3, '.', '')));
            }
            $itemEl->appendChild($out->createElementNS(self::NS, 'UP', number_format($up, 3, '.', '')));
            $itemEl->appendChild($out->createElementNS(self::NS, 'IT', number_format($it, 2, '.', '')));

            $gapFills = $bid->gapFills()[$rNo] ?? [];
            if ($gapFills !== []) {
                $itemEl->appendChild($this->buildDescription($out, $gapFills));
            }

            $comment = $bid->comments()[$rNo] ?? null;
            if ($comment !== null) {
                $itemEl->appendChild($this->buildBidComm($out, $comment));
            }

            $elements[] = $itemEl;
            if ($this->includedInTotal($item)) {
                $total += $it;
            }
        }

        return [$elements, $total];
    }

    private function includedInTotal(Item $item): bool
    {
        if ($item->provisional === Provisional::WithoutTotal) {
            return false;
        }

        return $item->alternativeGroupNo === null || $item->alternativeSerialNo === 1;
    }

    /** @param array<int, string> $gapFills markLabel => bidder text */
    private function buildDescription(\DOMDocument $out, array $gapFills): \DOMElement
    {
        $description = $out->createElementNS(self::NS, 'Description');
        $completeText = $out->createElementNS(self::NS, 'CompleteText');
        $detailTxt = $out->createElementNS(self::NS, 'DetailTxt');

        ksort($gapFills);
        foreach ($gapFills as $markLabel => $body) {
            $complement = $out->createElementNS(self::NS, 'TextComplement');
            $complement->setAttribute('MarkLbl', (string) $markLabel);
            $complement->setAttribute('Kind', 'Bidder');
            $complBody = $out->createElementNS(self::NS, 'ComplBody');
            $complBody->appendChild($this->textParagraph($out, $body));
            $complement->appendChild($complBody);
            $detailTxt->appendChild($complement);
        }

        $completeText->appendChild($detailTxt);
        $description->appendChild($completeText);

        return $description;
    }

    private function buildBidComm(\DOMDocument $out, string $comment): \DOMElement
    {
        $bidComm = $out->createElementNS(self::NS, 'BidComm');
        $bidComm->appendChild($this->textParagraph($out, $comment));

        return $bidComm;
    }

    private function textParagraph(\DOMDocument $out, string $text): \DOMElement
    {
        $p = $out->createElementNS(self::NS, 'p');
        $p->appendChild($out->createElementNS(self::NS, 'span', $text));

        return $p;
    }

    /** Clones $el into the target document under the DA84 namespace, preserving structure and text. */
    private function reNamespace(\DOMDocument $out, \DOMElement $el): \DOMElement
    {
        $new = $out->createElementNS(self::NS, $el->localName);
        foreach ($el->attributes ?? [] as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $new->appendChild($this->reNamespace($out, $child));
            } elseif ($child instanceof \DOMText) {
                $new->appendChild($out->createTextNode($child->wholeText));
            }
        }

        return $new;
    }
}
