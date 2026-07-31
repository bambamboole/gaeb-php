<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Write;

use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\Dto\Item;
use Bambamboole\Gaeb\Dto\Party;
use Bambamboole\Gaeb\Dto\Provisional;
use Bambamboole\Gaeb\Dto\TextComplementKind;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Xml\Dom;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Dom\Element;
use Dom\XMLDocument;

/**
 * @internal builds the X84 bid DOM from a source X81/X83 DOM plus its parsed
 * read model and a Bid collector. Kept out of GaebDocument to keep that
 * class thin.
 */
final class BidWriter extends Writer
{
    protected const string NS = 'http://www.gaeb.de/GAEB_DA_XML/DA84/3.3';

    private Bid $bid;

    /** @var array<string, Item> */
    private array $itemsByRNo;

    public function write(XMLDocument $source, GaebFile $file, Bid $bid): XMLDocument
    {
        $this->bid = $bid;
        $this->itemsByRNo = [];
        foreach ($file->boq?->allItems() ?? [] as $item) {
            $this->itemsByRNo[$item->rNo] = $item;
        }

        $this->assertPricesComplete();
        $this->assertKnownRNos();
        $this->assertNoNotApplicableReferences();
        $this->assertGapFillsMatchComplements();
        $this->assertNotOfferedUnpriced();

        $out = XMLDocument::createEmpty();
        $out->formatOutput = true;

        $root = $out->createElementNS(self::NS, 'GAEB');
        $out->appendChild($root);

        $root->appendChild($this->buildGaebInfo($out, $bid->date, $bid->progSystem));
        $root->appendChild($this->buildPrjInfo($out, $file));
        $root->appendChild($this->buildAward($out, $source, $file));

        return $out;
    }

    private function assertPricesComplete(): void
    {
        $missing = [];
        foreach ($this->itemsByRNo as $rNo => $item) {
            if (! $item->notApplicable
                && ! array_key_exists($rNo, $this->bid->prices())
                && ! isset($this->bid->notOffered()[$rNo])) {
                $missing[] = $rNo;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException('Missing unit price for priceable item(s): '.implode(', ', $missing));
        }
    }

    private function assertNotOfferedUnpriced(): void
    {
        $conflicting = array_keys(array_intersect_key($this->bid->notOffered(), $this->bid->prices()));
        if ($conflicting !== []) {
            throw new GaebWriteException('rNo(s) marked notOffered but also priced: '.implode(', ', $conflicting));
        }
    }

    /** @return list<string> */
    private function referencedRNos(): array
    {
        return [
            ...array_keys($this->bid->prices()),
            ...array_keys($this->bid->gapFills()),
            ...array_keys($this->bid->comments()),
            ...array_keys($this->bid->notOffered()),
        ];
    }

    private function assertKnownRNos(): void
    {
        $unknown = array_values(array_unique(array_diff($this->referencedRNos(), array_keys($this->itemsByRNo))));
        if ($unknown !== []) {
            throw new GaebWriteException('Unknown rNo(s) referenced in bid: '.implode(', ', $unknown));
        }
    }

    private function assertNoNotApplicableReferences(): void
    {
        $notApplicable = [];
        foreach (array_unique($this->referencedRNos()) as $rNo) {
            if ($this->itemsByRNo[$rNo]->notApplicable ?? false) {
                $notApplicable[] = $rNo;
            }
        }
        if ($notApplicable !== []) {
            throw new GaebWriteException('rNo(s) refer to notApplicable items: '.implode(', ', $notApplicable));
        }
    }

    private function assertGapFillsMatchComplements(): void
    {
        $invalid = [];
        foreach ($this->bid->gapFills() as $rNo => $markLabels) {
            $item = $this->itemsByRNo[$rNo] ?? null;
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

    private function buildAward(XMLDocument $out, XMLDocument $source, GaebFile $file): Element
    {
        $award = $out->createElementNS(self::NS, 'Award');
        $award->appendChild($this->elem($out, 'DP', '84'));

        $currency = $this->bid->currency ?? $file->project->currency ?? $file->boq?->currency;
        if ($currency === null) {
            throw new GaebWriteException('no currency found in source — set Bid::$currency');
        }
        $awardInfo = $out->createElementNS(self::NS, 'AwardInfo');
        $awardInfo->appendChild($this->elem($out, 'Cur', $currency));
        $award->appendChild($awardInfo);

        $award->appendChild($this->buildCTR($out, $this->bid->contractor));
        $award->appendChild($this->buildBoQ($out, $source));

        return $award;
    }

    private function buildCTR(XMLDocument $out, Party $contractor): Element
    {
        $this->assertRequired([
            'name' => $contractor->name,
            'street' => $contractor->street,
            'zip' => $contractor->zip,
            'city' => $contractor->city,
        ], 'Contractor is missing required field(s): ');

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

    private function buildBoQ(XMLDocument $out, XMLDocument $source): Element
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
            ? $this->buildBoQBody($out, $srcBody, '')
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
        $boqInfo->appendChild($this->totals($out, $total));
        $boq->appendChild($boqInfo);
        $boq->appendChild($bodyEl);

        return $boq;
    }

    /** @return ?array{Element, BigDecimal} */
    protected function buildBoQCtgy(XMLDocument $out, Element $srcCtgy, string $prefix): ?array
    {
        $rNoPart = Dom::attr($srcCtgy, 'RNoPart');
        $childPrefix = $prefix === '' ? $rNoPart : "{$prefix}.{$rNoPart}";

        $srcInnerBody = Dom::child($srcCtgy, 'BoQBody');
        [$bodyEl, $total] = $srcInnerBody !== null
            ? $this->buildBoQBody($out, $srcInnerBody, $childPrefix)
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
        $ctgy->appendChild($this->totals($out, $total));

        return [$ctgy, $total];
    }

    /** @return array{list<Element>, BigDecimal} */
    protected function buildItemlist(XMLDocument $out, Element $srcList, string $prefix): array
    {
        $elements = [];
        $total = BigDecimal::zero()->toScale(2);

        foreach (Dom::children($srcList, 'Item') as $srcItem) {
            $rNoPart = Dom::attr($srcItem, 'RNoPart');
            $rNoIndex = Dom::attr($srcItem, 'RNoIndex');
            $segment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
            $rNo = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            $item = $this->itemsByRNo[$rNo] ?? null;
            if ($item === null || $item->notApplicable) {
                continue;
            }

            $notOffered = isset($this->bid->notOffered()[$rNo]);
            // Round to the emitted precision first so UP x Qty == IT holds
            // in the document a consumer actually reads back.
            $up = $notOffered ? null : $this->bid->prices()[$rNo]->toScale(3, RoundingMode::HalfUp);
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
            $it = null;
            if ($up !== null) {
                $it = $qty !== null
                    ? $qty->multipliedBy($up)->toScale(2, RoundingMode::HalfUp)
                    : $up->toScale(2, RoundingMode::HalfUp);
            }

            $itemEl = $out->createElementNS(self::NS, 'Item');
            $itemEl->setAttribute('ID', Dom::attr($srcItem, 'ID'));
            $itemEl->setAttribute('RNoPart', $rNoPart);
            if ($rNoIndex !== '') {
                $itemEl->setAttribute('RNoIndex', $rNoIndex);
            }
            if ($notOffered) {
                $itemEl->appendChild($this->elem($out, 'NotOffered', 'Yes'));
            }
            if ($qty !== null) {
                $itemEl->appendChild($this->elem($out, 'Qty', (string) $qty->toScale(3, RoundingMode::HalfUp)));
            }
            if ($up !== null && $it !== null) {
                $itemEl->appendChild($this->elem($out, 'UP', (string) $up));
                $itemEl->appendChild($this->elem($out, 'IT', (string) $it));
            }

            $gapFills = $this->bid->gapFills()[$rNo] ?? [];
            if ($gapFills !== []) {
                $itemEl->appendChild($this->buildDescription($out, $gapFills));
            }

            $comment = $this->bid->comments()[$rNo] ?? null;
            if ($comment !== null) {
                $itemEl->appendChild($this->buildBidComm($out, $comment));
            }

            $elements[] = $itemEl;
            if ($it !== null && $this->includedInTotal($item)) {
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
}
