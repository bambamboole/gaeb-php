<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Write;

use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\Dto\Item;
use Bambamboole\Gaeb\Dto\Party;
use Bambamboole\Gaeb\Dto\Payment;
use Bambamboole\Gaeb\Dto\SettlementType;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Xml\Dom;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Dom\Element;
use Dom\Text;
use Dom\XMLDocument;

/**
 * @internal builds the X89 invoice DOM from a source X86 contract DOM plus
 * its parsed read model and an Invoice collector. Mirrors BidWriter; see
 * docs/superpowers/specs/2026-07-31-x89-invoicing-design.md.
 */
final class InvoiceWriter
{
    private const NS = 'http://www.gaeb.de/GAEB_DA_XML/DA89/3.3';

    public function write(XMLDocument $source, GaebFile $file, Invoice $invoice): XMLDocument
    {
        /** @var array<string, Item> $itemsByRNo */
        $itemsByRNo = [];
        foreach ($file->boq?->allItems() ?? [] as $item) {
            $itemsByRNo[$item->rNo] = $item;
        }

        $unknown = array_values(array_diff(array_keys($invoice->quantities()), array_keys($itemsByRNo)));
        if ($unknown !== []) {
            throw new GaebWriteException('Unknown rNo(s) referenced in invoice: '.implode(', ', $unknown));
        }
        if ($invoice->quantities() === []) {
            throw new GaebWriteException('Invoice bills no items');
        }
        $notApplicable = [];
        foreach (array_keys($invoice->quantities()) as $rNo) {
            if ($itemsByRNo[$rNo]->notApplicable) {
                $notApplicable[] = $rNo;
            }
        }
        if ($notApplicable !== []) {
            throw new GaebWriteException('rNo(s) refer to notApplicable items: '.implode(', ', $notApplicable));
        }
        $vat = $this->resolveVat($source, $invoice);

        $out = XMLDocument::createEmpty();
        $out->formatOutput = true;

        $root = $out->createElementNS(self::NS, 'GAEB');
        $out->appendChild($root);
        $root->appendChild($this->buildGaebInfo($out, $invoice));
        $root->appendChild($this->buildPrjInfo($out, $file));
        $root->appendChild($this->buildInvoice($out, $source, $file, $invoice, $vat));

        return $out;
    }

    private function resolveVat(XMLDocument $source, Invoice $invoice): BigDecimal
    {
        $vat = $invoice->vatPercent;
        if ($vat === null) {
            $srcRoot = $source->documentElement;
            $award = $srcRoot !== null ? Dom::child($srcRoot, 'Award') : null;
            $boqInfo = ($boq = $award !== null ? Dom::child($award, 'BoQ') : null) !== null ? Dom::child($boq, 'BoQInfo') : null;
            $totals = $boqInfo !== null ? Dom::child($boqInfo, 'Totals') : null;
            $vat = $totals !== null ? Dom::text($totals, 'VAT') : null;
        }
        if ($vat === null) {
            throw new GaebWriteException('No VAT rate: source BoQ Totals carries no VAT and Invoice::$vatPercent is not set');
        }
        try {
            return BigDecimal::of($vat);
        } catch (MathException $e) {
            throw new GaebWriteException("Invalid VAT rate \"{$vat}\": {$e->getMessage()}", previous: $e);
        }
    }

    private function buildGaebInfo(XMLDocument $out, Invoice $invoice): Element
    {
        $info = $out->createElementNS(self::NS, 'GAEBInfo');
        $info->appendChild($this->elem($out, 'Version', '3.3'));
        $info->appendChild($this->elem($out, 'VersDate', '2021-05'));
        $info->appendChild($this->elem($out, 'Date', $invoice->date ?? date('Y-m-d')));
        $info->appendChild($this->elem($out, 'ProgSystem', $invoice->progSystem));

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

    private function buildInvoice(XMLDocument $out, XMLDocument $source, GaebFile $file, Invoice $invoice, BigDecimal $vat): Element
    {
        $srcRoot = $source->documentElement;
        $srcAward = $srcRoot !== null ? Dom::child($srcRoot, 'Award') : null;

        $el = $out->createElementNS(self::NS, 'Invoice');
        $el->appendChild($this->elem($out, 'DP', '89'));

        // Parties copied from the contract (X86 requires both).
        foreach (['OWN', 'CTR'] as $party) {
            $srcParty = $srcAward !== null ? Dom::child($srcAward, $party) : null;
            if ($srcParty !== null) {
                $el->appendChild($this->reNamespace($out, $srcParty));
            }
        }

        $cnstSite = $out->createElementNS(self::NS, 'CnstSite');
        // 60-char ceiling is tgNormalizedString60, schema-mandated.
        $cnstSite->appendChild($this->elem($out, 'CnstSiteName', mb_substr((string) $file->project->name, 0, 60)));
        $el->appendChild($cnstSite);

        [$boqEl, , $gross] = $this->buildBoQ($out, $source, $invoice, $vat);
        $el->appendChild($boqEl);

        $el->appendChild($this->buildInvoiceHeader($out, $invoice));
        $el->appendChild($this->buildInvoiceParty($out, 'InvoiceCreator', $file->contractor, $invoice->creatorTaxNo));
        $el->appendChild($this->buildInvoiceParty($out, 'InvoiceRecipient', $file->owner, null));

        $share = $out->createElementNS(self::NS, 'InvoiceShare');
        $share->appendChild($this->elem($out, 'InvoiceShareType', 'basic amount'));
        $share->appendChild($this->elem($out, 'Description', 'Grundbetrag'));
        // Emitted at GROSS (= TotalGross): GAEB's InvoiceShare annotation is
        // non-committal on net vs. gross for a single share. Revisit against
        // the Rechnung Anwendungsdokumentation if multi-share support lands.
        $share->appendChild($this->elem($out, 'Total', (string) $gross));
        $el->appendChild($share);

        foreach ($invoice->payments() as $payment) {
            $el->appendChild($this->buildPaymentMade($out, $payment));
        }

        $el->appendChild($this->elem($out, 'TotalGross', (string) $gross));

        return $el;
    }

    private function buildInvoiceHeader(XMLDocument $out, Invoice $invoice): Element
    {
        $header = $out->createElementNS(self::NS, 'InvoiceHeader');
        $header->appendChild($this->elem($out, 'InvoiceNo', $invoice->invoiceNo));
        $header->appendChild($this->elem($out, 'InvoiceDate', $invoice->invoiceDate));
        $header->appendChild($this->elem($out, 'InvoiceType', $invoice->type->value));
        if ($invoice->creditNote) {
            $header->appendChild($this->elem($out, 'CreditNote', 'Yes'));
        }
        $header->appendChild($this->elem($out, 'SettlementType', SettlementType::Accumulated->value));
        if ($invoice->sequentialNo !== null) {
            $header->appendChild($this->elem($out, 'SequentialNo', (string) $invoice->sequentialNo));
        }
        $header->appendChild($this->elem($out, 'ServiceProvisionStartDate', $invoice->servicePeriodStart));
        $header->appendChild($this->elem($out, 'ServiceProvisionEndDate', $invoice->servicePeriodEnd));

        return $header;
    }

    private function buildInvoiceParty(XMLDocument $out, string $name, ?Party $party, ?string $taxNo): Element
    {
        $missing = [];
        foreach (['name' => $party?->name, 'street' => $party?->street, 'zip' => $party?->zip, 'city' => $party?->city] as $field => $value) {
            if ($value === null) {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException("{$name} address is missing required field(s): ".implode(', ', $missing));
        }

        $el = $out->createElementNS(self::NS, $name);
        $address = $out->createElementNS(self::NS, 'Address');
        $address->appendChild($this->elem($out, 'Name1', (string) $party->name));
        $address->appendChild($this->elem($out, 'Street', (string) $party->street));
        $address->appendChild($this->elem($out, 'PCode', (string) $party->zip));
        $address->appendChild($this->elem($out, 'City', (string) $party->city));
        $el->appendChild($address);
        if ($taxNo !== null) {
            $el->appendChild($this->elem($out, 'TaxNo', $taxNo));
        }

        return $el;
    }

    private function buildPaymentMade(XMLDocument $out, Payment $payment): Element
    {
        $missing = [];
        foreach (['total' => $payment->total, 'totalVat' => $payment->totalVat, 'paymentDate' => $payment->paymentDate, 'invoiceNo' => $payment->invoiceNo] as $field => $value) {
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException('Payment is missing required field(s): '.implode(', ', $missing));
        }

        $el = $out->createElementNS(self::NS, 'PaymentMade');
        $el->appendChild($this->elem($out, 'TotalVAT', (string) $this->decimal($payment->totalVat, 'payment totalVat')));
        if ($payment->discountAmount !== null) {
            $el->appendChild($this->elem($out, 'DiscountAmt', (string) $this->decimal($payment->discountAmount, 'payment discountAmount')));
        }
        $el->appendChild($this->elem($out, 'Total', (string) $this->decimal($payment->total, 'payment total')));
        $el->appendChild($this->elem($out, 'PaymentDate', (string) $payment->paymentDate));
        $el->appendChild($this->elem($out, 'InvoiceNo', (string) $payment->invoiceNo));
        if ($payment->paymentNo !== null) {
            $el->appendChild($this->elem($out, 'PaymentNo', $payment->paymentNo));
        }
        if ($payment->paymentNote !== null) {
            $el->appendChild($this->elem($out, 'PaymentNote', $payment->paymentNote));
        }

        return $el;
    }

    private function decimal(?string $value, string $label): BigDecimal
    {
        try {
            return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HalfUp);
        } catch (MathException $e) {
            throw new GaebWriteException("Invalid {$label} \"{$value}\": {$e->getMessage()}", previous: $e);
        }
    }

    /** @return array{Element, BigDecimal, BigDecimal} net and gross totals, so callers never recompute VAT/gross themselves */
    private function buildBoQ(XMLDocument $out, XMLDocument $source, Invoice $invoice, BigDecimal $vat): array
    {
        $srcRoot = $source->documentElement;
        $srcAward = $srcRoot !== null ? Dom::child($srcRoot, 'Award') : null;
        $srcBoQ = $srcAward !== null ? Dom::child($srcAward, 'BoQ') : null;
        if ($srcBoQ === null) {
            throw new GaebWriteException('Source document has no BoQ; cannot create an invoice.');
        }

        $srcBoQInfo = Dom::child($srcBoQ, 'BoQInfo');
        if ($srcBoQInfo === null) {
            throw new GaebWriteException('Source BoQ has no BoQInfo; cannot create an invoice.');
        }
        $name = Dom::text($srcBoQInfo, 'Name');
        $lblBoQ = Dom::text($srcBoQInfo, 'LblBoQ');
        $outlCompl = Dom::text($srcBoQInfo, 'OutlCompl');
        if ($name === null || $lblBoQ === null || $outlCompl === null) {
            throw new GaebWriteException('Source BoQInfo is missing required field(s) (Name, LblBoQ, OutlCompl); cannot create an invoice.');
        }

        $srcBody = Dom::child($srcBoQ, 'BoQBody');
        [$bodyEl, $net] = $srcBody !== null
            ? $this->buildBoQBody($out, $srcBody, '', $invoice)
            : [null, BigDecimal::zero()->toScale(2)];
        if ($bodyEl === null) {
            // tgBoQ requires BoQBody — this cannot happen once quantities
            // are non-empty and rNos are known (guarded upstream), but the
            // strict-write contract refuses to emit a hollow BoQ.
            throw new GaebWriteException('Invoice contains no items');
        }

        $boq = $out->createElementNS(self::NS, 'BoQ');
        $boq->setAttribute('ID', Dom::attr($srcBoQ, 'ID'));

        $boqInfo = $out->createElementNS(self::NS, 'BoQInfo');
        $boqInfo->appendChild($this->elem($out, 'Name', $name));
        $boqInfo->appendChild($this->elem($out, 'LblBoQ', $lblBoQ));
        $boqInfo->appendChild($this->elem($out, 'OutlCompl', $outlCompl));
        foreach (Dom::children($srcBoQInfo, 'BoQBkdn') as $bkdn) {
            $boqInfo->appendChild($this->reNamespace($out, $bkdn));
        }

        $vatAmount = $net->multipliedBy($vat)->dividedBy(100, 2, RoundingMode::HalfUp);
        $gross = $net->plus($vatAmount)->toScale(2, RoundingMode::HalfUp);

        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($this->elem($out, 'Total', (string) $net));
        $totalsEl->appendChild($this->elem($out, 'VAT', (string) $vat->toScale(2, RoundingMode::HalfUp)));
        $totalsEl->appendChild($this->elem($out, 'TotalNet', (string) $net));
        $totalsEl->appendChild($this->elem($out, 'VATAmount', (string) $vatAmount));
        $totalsEl->appendChild($this->elem($out, 'TotalGross', (string) $gross));
        $boqInfo->appendChild($totalsEl);

        $boq->appendChild($boqInfo);
        $boq->appendChild($bodyEl);

        return [$boq, $net, $gross];
    }

    /** @return array{?Element, BigDecimal} */
    private function buildBoQBody(XMLDocument $out, Element $srcBody, string $prefix, Invoice $invoice): array
    {
        $bodyEl = null;
        $total = BigDecimal::zero()->toScale(2);

        foreach ($srcBody->childNodes as $node) {
            if (! $node instanceof Element) {
                continue;
            }
            if ($node->localName === 'BoQCtgy') {
                $built = $this->buildBoQCtgy($out, $node, $prefix, $invoice);
                if ($built === null) {
                    continue;
                }
                [$ctgyEl, $ctgyTotal] = $built;
                $bodyEl ??= $out->createElementNS(self::NS, 'BoQBody');
                $bodyEl->appendChild($ctgyEl);
                $total = $total->plus($ctgyTotal);
            } elseif ($node->localName === 'Itemlist') {
                [$itemEls, $listTotal] = $this->buildItemlist($out, $node, $prefix, $invoice);
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

    /** @return ?array{Element, BigDecimal} */
    private function buildBoQCtgy(XMLDocument $out, Element $srcCtgy, string $prefix, Invoice $invoice): ?array
    {
        $rNoPart = Dom::attr($srcCtgy, 'RNoPart');
        $childPrefix = $prefix === '' ? $rNoPart : "{$prefix}.{$rNoPart}";

        $srcInnerBody = Dom::child($srcCtgy, 'BoQBody');
        [$bodyEl, $total] = $srcInnerBody !== null
            ? $this->buildBoQBody($out, $srcInnerBody, $childPrefix, $invoice)
            : [null, BigDecimal::zero()->toScale(2)];

        if ($bodyEl === null) {
            // Nothing under this category was billed — an invoice bills
            // real work, so an empty BoQCtgy is dropped entirely rather
            // than emitted with a hollow Totals/Total 0.00.
            return null;
        }

        $lblTx = Dom::child($srcCtgy, 'LblTx');
        if ($lblTx === null) {
            throw new GaebWriteException("Source BoQCtgy {$childPrefix} has no LblTx; cannot create an invoice.");
        }

        $ctgy = $out->createElementNS(self::NS, 'BoQCtgy');
        $ctgy->setAttribute('ID', Dom::attr($srcCtgy, 'ID'));
        $ctgy->setAttribute('RNoPart', $rNoPart);
        $ctgy->appendChild($this->reNamespace($out, $lblTx));
        $ctgy->appendChild($bodyEl);

        $totalsEl = $out->createElementNS(self::NS, 'Totals');
        $totalsEl->appendChild($this->elem($out, 'Total', (string) $total));
        $ctgy->appendChild($totalsEl);

        return [$ctgy, $total];
    }

    /** @return array{list<Element>, BigDecimal} */
    private function buildItemlist(XMLDocument $out, Element $srcList, string $prefix, Invoice $invoice): array
    {
        $elements = [];
        $total = BigDecimal::zero()->toScale(2);
        $quantities = $invoice->quantities();

        foreach (Dom::children($srcList, 'Item') as $srcItem) {
            $rNoPart = Dom::attr($srcItem, 'RNoPart');
            $rNoIndex = Dom::attr($srcItem, 'RNoIndex');
            $segment = $rNoIndex !== '' ? "{$rNoPart}.{$rNoIndex}" : $rNoPart;
            $rNo = $prefix === '' ? $segment : "{$prefix}.{$segment}";

            $billQty = $quantities[$rNo] ?? null;
            if ($billQty === null) {
                // Partial billing is the normal case: unbilled items are
                // silently omitted, not an error.
                continue;
            }
            $billQty = $billQty->toScale(3, RoundingMode::HalfUp);

            $upString = Dom::text($srcItem, 'UP');
            if ($upString === null) {
                throw new GaebWriteException("Item {$rNo} has no usable unit price in the contract: missing UP");
            }
            try {
                $up = BigDecimal::of($upString)->toScale(3, RoundingMode::HalfUp);
            } catch (MathException $e) {
                throw new GaebWriteException("Item {$rNo} has no usable unit price in the contract: {$e->getMessage()}", previous: $e);
            }

            $it = $billQty->multipliedBy($up)->toScale(2, RoundingMode::HalfUp);

            $itemEl = $out->createElementNS(self::NS, 'Item');
            $itemEl->setAttribute('ID', Dom::attr($srcItem, 'ID'));
            $itemEl->setAttribute('RNoPart', $rNoPart);
            if ($rNoIndex !== '') {
                $itemEl->setAttribute('RNoIndex', $rNoIndex);
            }

            $lumpSum = Dom::text($srcItem, 'LumpSumItem') === 'Yes';
            if ($lumpSum) {
                $itemEl->appendChild($this->elem($out, 'LumpSumItem', 'Yes'));
            }
            $itemEl->appendChild($this->elem($out, 'BillQty', (string) $billQty));

            $qu = Dom::text($srcItem, 'QU');
            if ($qu === null) {
                throw new GaebWriteException("Item {$rNo} has no unit (QU) in the contract");
            }
            $itemEl->appendChild($this->elem($out, 'QU', $qu));
            $itemEl->appendChild($this->elem($out, 'UP', (string) $up));
            $itemEl->appendChild($this->elem($out, 'IT', (string) $it));

            $description = Dom::child($srcItem, 'Description');
            if ($description !== null) {
                $itemEl->appendChild($this->reNamespace($out, $description));
            }

            $elements[] = $itemEl;
            $total = $total->plus($it);
        }

        return [$elements, $total];
    }

    /** Creates a DA89-namespaced element, optionally with text content — createElementNS has no 3-arg text shorthand in the native Dom API. */
    private function elem(XMLDocument $out, string $name, ?string $text = null): Element
    {
        $el = $out->createElementNS(self::NS, $name);
        if ($text !== null) {
            $el->textContent = $text;
        }

        return $el;
    }

    /** Clones $el into the target document under the DA89 namespace, preserving structure and text. */
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
