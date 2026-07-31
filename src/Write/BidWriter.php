<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Write;

use Bambamboole\Gaeb\Dto\Contractor;
use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\Dto\Item;
use Bambamboole\Gaeb\Dto\Provisional;
use Bambamboole\Gaeb\Dto\TextComplementKind;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Xml\Dom;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Dom\Element;
use Dom\Text;
use Dom\XMLDocument;

/**
 * @internal builds the X84 bid DOM from a source X81/X83 DOM plus its parsed
 * read model and a Bid collector. Kept out of GaebDocument to keep that
 * class thin; see docs/superpowers/specs/2026-07-31-write-foundation-x84-bid-design.md
 * for the emission rules this implements.
 */
final class BidWriter
{
    private const NS = 'http://www.gaeb.de/GAEB_DA_XML/DA84/3.3';

    public function write(XMLDocument $source, GaebFile $file, Bid $bid): XMLDocument
    {
        /** @var array<string, Item> $itemsByRNo */
        $itemsByRNo = [];
        foreach ($file->boq?->allItems() ?? [] as $item) {
            $itemsByRNo[$item->rNo] = $item;
        }

        $this->assertPricesComplete($itemsByRNo, $bid);
        $this->assertKnownRNos($itemsByRNo, $bid);
        $this->assertNoNotApplicableReferences($itemsByRNo, $bid);
        $this->assertGapFillsMatchComplements($itemsByRNo, $bid);

        $out = XMLDocument::createEmpty();
        $out->formatOutput = true;

        $root = $out->createElementNS(self::NS, 'GAEB');
        $out->appendChild($root);

        $root->appendChild($this->buildGaebInfo($out, $bid));
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

    /** @param array<string, Item> $itemsByRNo */
    private function assertNoNotApplicableReferences(array $itemsByRNo, Bid $bid): void
    {
        $referenced = [
            ...array_keys($bid->prices()),
            ...array_keys($bid->gapFills()),
            ...array_keys($bid->comments()),
        ];
        $notApplicable = [];
        foreach (array_unique($referenced) as $rNo) {
            if ($itemsByRNo[$rNo]->notApplicable ?? false) {
                $notApplicable[] = $rNo;
            }
        }
        if ($notApplicable !== []) {
            throw new GaebWriteException('rNo(s) refer to notApplicable items: '.implode(', ', $notApplicable));
        }
    }

    /** @param array<string, Item> $itemsByRNo */
    private function assertGapFillsMatchComplements(array $itemsByRNo, Bid $bid): void
    {
        $invalid = [];
        foreach ($bid->gapFills() as $rNo => $markLabels) {
            $item = $itemsByRNo[$rNo] ?? null;
            if ($item === null) {
                continue; // already reported by assertKnownRNos
            }
            $bidderMarkLabels = [];
            foreach ($item->textComplements as $complement) {
                if ($complement->kind === TextComplementKind::Bidder) {
                    $bidderMarkLabels[$complement->markLabel] = true;
                }
            }
            foreach (array_keys($markLabels) as $markLabel) {
                if (! isset($bidderMarkLabels[$markLabel])) {
                    $invalid[] = "{$rNo} markLabel {$markLabel}";
                }
            }
        }
        if ($invalid !== []) {
            throw new GaebWriteException('Gap fill(s) reference markLabel(s) with no matching Bidder complement: '.implode(', ', $invalid));
        }
    }

    private function buildGaebInfo(XMLDocument $out, Bid $bid): Element
    {
        $info = $out->createElementNS(self::NS, 'GAEBInfo');
        $info->appendChild($this->elem($out, 'Version', '3.3'));
        $info->appendChild($this->elem($out, 'VersDate', '2021-05'));
        $info->appendChild($this->elem($out, 'Date', $bid->date ?? date('Y-m-d')));
        $info->appendChild($this->elem($out, 'ProgSystem', $bid->progSystem));

        return $info;
    }

    private function buildPrjInfo(XMLDocument $out, GaebFile $file): Element
    {
        $name = $file->project->name;
        if ($name === null) {
            throw new GaebWriteException('Source project has no name; cannot write PrjInfo/NamePrj.');
        }

        $prj = $out->createElementNS(self::NS, 'PrjInfo');
        $prj->appendChild($this->elem($out, 'NamePrj', $name));
        if ($file->project->label !== null) {
            $prj->appendChild($this->elem($out, 'LblPrj', $file->project->label));
        }

        return $prj;
    }

    /** @param array<string, Item> $itemsByRNo */
    private function buildAward(XMLDocument $out, XMLDocument $source, GaebFile $file, Bid $bid, array $itemsByRNo): Element
    {
        $award = $out->createElementNS(self::NS, 'Award');
        $award->appendChild($this->elem($out, 'DP', '84'));

        $currency = $bid->currency ?? $file->project->currency ?? $file->boq?->currency;
        if ($currency === null) {
            throw new GaebWriteException('no currency found in source — set Bid::$currency');
        }
        $awardInfo = $out->createElementNS(self::NS, 'AwardInfo');
        $awardInfo->appendChild($this->elem($out, 'Cur', $currency));
        $award->appendChild($awardInfo);

        $award->appendChild($this->buildCTR($out, $bid->contractor));
        $award->appendChild($this->buildBoQ($out, $source, $file, $bid, $itemsByRNo));

        return $award;
    }

    private function buildCTR(XMLDocument $out, Contractor $contractor): Element
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
        $address->appendChild($this->elem($out, 'Name1', (string) $contractor->name));
        $address->appendChild($this->elem($out, 'Street', (string) $contractor->street));
        $address->appendChild($this->elem($out, 'PCode', (string) $contractor->zip));
        $address->appendChild($this->elem($out, 'City', (string) $contractor->city));
        if ($contractor->phone !== null) {
            $address->appendChild($this->elem($out, 'Phone', $contractor->phone));
        }
        if ($contractor->email !== null) {
            $address->appendChild($this->elem($out, 'Email', $contractor->email));
        }
        $ctr->appendChild($address);

        return $ctr;
    }

    /** @param array<string, Item> $itemsByRNo */
    private function buildBoQ(XMLDocument $out, XMLDocument $source, GaebFile $file, Bid $bid, array $itemsByRNo): Element
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
            : [null, BigDecimal::zero()];
        if ($bodyEl === null) {
            // tgBoQ requires BoQBody — every item ended up notApplicable
            // (or the source had none to begin with). Emitting an X84
            // without one would be schema-invalid; strict-write refuses.
            throw new GaebWriteException('Bid contains no items');
        }

        $boq = $out->createElementNS(self::NS, 'BoQ');
        $boq->setAttribute('ID', Dom::attr($srcBoQ, 'ID'));

        $boqInfo = $out->createElementNS(self::NS, 'BoQInfo');
        $boqInfo->appendChild($this->elem($out, 'Name', $name));
        foreach (Dom::children($srcBoQInfo, 'BoQBkdn') as $bkdn) {
            $boqInfo->appendChild($this->reNamespace($out, $bkdn));
        }
        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($this->elem($out, 'Total', (string) $total));
        $boqInfo->appendChild($totalsEl);
        $boq->appendChild($boqInfo);
        $boq->appendChild($bodyEl);

        return $boq;
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return array{?Element, BigDecimal}
     */
    private function buildBoQBody(XMLDocument $out, Element $srcBody, string $prefix, Bid $bid, array $itemsByRNo): array
    {
        $bodyEl = null;
        $total = BigDecimal::zero()->toScale(2);

        foreach ($srcBody->childNodes as $node) {
            if (! $node instanceof Element) {
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
                $total = $total->plus($ctgyTotal);
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
                $total = $total->plus($listTotal);
            }
        }

        return [$bodyEl, $total];
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return ?array{Element, BigDecimal}
     */
    private function buildBoQCtgy(XMLDocument $out, Element $srcCtgy, string $prefix, Bid $bid, array $itemsByRNo): ?array
    {
        $rNoPart = Dom::attr($srcCtgy, 'RNoPart');
        $childPrefix = $prefix === '' ? $rNoPart : "{$prefix}.{$rNoPart}";

        $srcInnerBody = Dom::child($srcCtgy, 'BoQBody');
        [$bodyEl, $total] = $srcInnerBody !== null
            ? $this->buildBoQBody($out, $srcInnerBody, $childPrefix, $bid, $itemsByRNo)
            : [null, BigDecimal::zero()->toScale(2)];

        if ($bodyEl === null) {
            // Every item under this category was notApplicable (or there
            // were none to begin with) — an empty BoQCtgy has nothing to
            // offer against, so it is dropped entirely rather than emitted
            // with a hollow Totals/Total 0.00.
            return null;
        }

        $ctgy = $out->createElementNS(self::NS, 'BoQCtgy');
        $ctgy->setAttribute('ID', Dom::attr($srcCtgy, 'ID'));
        $ctgy->setAttribute('RNoPart', $rNoPart);
        $ctgy->appendChild($bodyEl);

        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($this->elem($out, 'Total', (string) $total));
        $ctgy->appendChild($totalsEl);

        return [$ctgy, $total];
    }

    /**
     * @param  array<string, Item>  $itemsByRNo
     * @return array{list<Element>, BigDecimal}
     */
    private function buildItemlist(XMLDocument $out, Element $srcList, string $prefix, Bid $bid, array $itemsByRNo): array
    {
        $elements = [];
        $total = BigDecimal::zero()->toScale(2);

        foreach (Dom::children($srcList, 'Item') as $srcItem) {
            $rNoPart = Dom::attr($srcItem, 'RNoPart');
            $rNoIndex = Dom::attr($srcItem, 'RNoIndex');
            $segment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
            $rNo = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            $item = $itemsByRNo[$rNo] ?? null;
            if ($item === null || $item->notApplicable) {
                continue;
            }

            // Round to the emitted precision first so UP x Qty == IT holds
            // in the document a consumer actually reads back.
            $up = $bid->prices()[$rNo]->toScale(3, RoundingMode::HalfUp);
            $qty = null;
            if ($item->qty !== null) {
                // Read qty from the SOURCE decimal string — no float hop.
                // A priced item always carries a Qty in the source; the
                // read-model float is an unreachable fallback.
                $qtyString = Dom::text($srcItem, 'Qty') ?? (string) $item->qty;
                try {
                    $qty = BigDecimal::of($qtyString);
                } catch (MathException) {
                    throw new GaebWriteException("Item {$rNo} has a non-numeric quantity: \"{$qtyString}\"");
                }
            }
            $it = $qty !== null
                ? $qty->multipliedBy($up)->toScale(2, RoundingMode::HalfUp)
                : $up->toScale(2, RoundingMode::HalfUp);

            $itemEl = $out->createElementNS(self::NS, 'Item');
            $itemEl->setAttribute('ID', Dom::attr($srcItem, 'ID'));
            $itemEl->setAttribute('RNoPart', $rNoPart);
            if ($rNoIndex !== '') {
                $itemEl->setAttribute('RNoIndex', $rNoIndex);
            }
            if ($qty !== null) {
                $itemEl->appendChild($this->elem($out, 'Qty', (string) $qty->toScale(3, RoundingMode::HalfUp)));
            }
            $itemEl->appendChild($this->elem($out, 'UP', (string) $up));
            $itemEl->appendChild($this->elem($out, 'IT', (string) $it));

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
                $total = $total->plus($it);
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
    private function buildDescription(XMLDocument $out, array $gapFills): Element
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

    private function buildBidComm(XMLDocument $out, string $comment): Element
    {
        $bidComm = $out->createElementNS(self::NS, 'BidComm');
        $bidComm->appendChild($this->textParagraph($out, $comment));

        return $bidComm;
    }

    private function textParagraph(XMLDocument $out, string $text): Element
    {
        $p = $out->createElementNS(self::NS, 'p');
        $p->appendChild($this->elem($out, 'span', $text));

        return $p;
    }

    /** Creates a DA84-namespaced element, optionally with text content — createElementNS has no 3-arg text shorthand in the native Dom API. */
    private function elem(XMLDocument $out, string $name, ?string $text = null): Element
    {
        $el = $out->createElementNS(self::NS, $name);
        if ($text !== null) {
            $el->textContent = $text;
        }

        return $el;
    }

    /** Clones $el into the target document under the DA84 namespace, preserving structure and text. */
    private function reNamespace(XMLDocument $out, Element $el): Element
    {
        $new = $out->createElementNS(self::NS, $el->localName);
        foreach ($el->attributes ?? [] as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof Element) {
                $new->appendChild($this->reNamespace($out, $child));
            } elseif ($child instanceof Text) {
                $new->appendChild($out->createTextNode($child->wholeText));
            }
        }

        return $new;
    }
}
