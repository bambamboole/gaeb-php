<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\InvoiceType;
use Bambamboole\Gaeb\Dto\Payment;
use Bambamboole\Gaeb\Dto\SettlementType;
use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Write\Invoice;

function contractDocument(): GaebDocument
{
    return GaebDocument::open(__DIR__.'/fixtures/contract.x86');
}

it('creates a schema-valid cumulative X89 invoice from the X86 contract', function () {
    $invoice = new Invoice(
        invoiceNo: 'RE-2026-001',
        invoiceDate: '2026-10-31',
        type: InvoiceType::Deduction,
        servicePeriodStart: '2026-09-01',
        servicePeriodEnd: '2026-10-31',
        creatorTaxNo: 'DE123456789',
        vatPercent: '19',
        date: '2026-10-31',
    );
    $invoice->billQty('01.0010', '30')
        ->billQty('01.0020', '1')
        ->addPayment(new Payment(
            total: '1190.00',
            totalVat: '190.00',
            discountAmount: null,
            paymentDate: '2026-10-05',
            invoiceNo: 'RE-2026-000',
        ));

    $doc = contractDocument()->createInvoice($invoice);

    expect($doc->validate())->toBe([]);

    $gaeb = $doc->file();
    // contract items: 01.0010 UP 45.50, 01.0020 UP 1200.00 (lump sum)
    // 30 x 45.500 = 1365.00; 1 x 1200.000 = 1200.00; net 2565.00
    // VAT 19% = 487.35; gross 3052.35
    expect($gaeb->info->phase)->toBe(89)
        ->and($gaeb->invoice->invoiceNo)->toBe('RE-2026-001')
        ->and($gaeb->invoice->type)->toBe(InvoiceType::Deduction)
        ->and($gaeb->invoice->settlementType)->toBe(SettlementType::Accumulated)
        ->and($gaeb->invoice->servicePeriodStart)->toBe('2026-09-01')
        ->and($gaeb->invoice->creator->name)->toBe('Musterbau GmbH')
        ->and($gaeb->invoice->creator->taxNo)->toBe('DE123456789')
        ->and($gaeb->invoice->recipient->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->invoice->payments)->toHaveCount(1)
        ->and($gaeb->invoice->payments[0]->total)->toBe('1190.00')
        ->and($gaeb->invoice->totalGross)->toBe(3052.35);

    $items = iterator_to_array($gaeb->boq->allItems(), false);
    expect($items)->toHaveCount(2)
        ->and($items[0]->rNo)->toBe('01.0010')
        ->and($items[0]->billedQty)->toBe(30.0)
        ->and($items[0]->unitPrice)->toBe(45.5)
        ->and($items[0]->totalPrice)->toBe(1365.00)
        ->and($items[1]->totalPrice)->toBe(1200.00)
        ->and($gaeb->boq->totals->total)->toBe(2565.00)
        ->and($gaeb->boq->totals->vatAmount)->toBe(487.35)
        ->and($gaeb->boq->totals->totalGross)->toBe(3052.35)
        ->and($gaeb->owner->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH');
});

it('bills a subset of items without touching the rest', function () {
    $invoice = new Invoice('RE-2026-002', '2026-11-30', InvoiceType::Deduction, '2026-11-01', '2026-11-30', 'DE123456789', vatPercent: '19', date: '2026-11-30');
    $invoice->billQty('01.0010', '12.5');

    $gaeb = contractDocument()->createInvoice($invoice)->file();

    $items = iterator_to_array($gaeb->boq->allItems(), false);
    expect($items)->toHaveCount(1)
        ->and($items[0]->rNo)->toBe('01.0010')
        ->and($items[0]->billedQty)->toBe(12.5)
        ->and($items[0]->totalPrice)->toBe(568.75)  // 12.5 x 45.500
        ->and($gaeb->boq->totals->total)->toBe(568.75);
});

it('rejects unknown rNos', function () {
    $invoice = new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789', vatPercent: '19');
    $invoice->billQty('99.9999', '1');

    contractDocument()->createInvoice($invoice);
})->throws(GaebWriteException::class, 'Unknown rNo');

it('rejects an invoice with no billed items', function () {
    $invoice = new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789', vatPercent: '19');

    contractDocument()->createInvoice($invoice);
})->throws(GaebWriteException::class, 'bills no items');

it('rejects a non-X86 source', function () {
    $invoice = new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789', vatPercent: '19');
    $invoice->billQty('01.0010', '1');

    GaebDocument::open(__DIR__.'/fixtures/boq.x83')->createInvoice($invoice);
})->throws(GaebWriteException::class, 'requires an X86 source');

it('rejects a missing VAT rate instead of defaulting', function () {
    // contract.x86's Totals carries no VAT element and no override is given
    $invoice = new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789');
    $invoice->billQty('01.0010', '1');

    contractDocument()->createInvoice($invoice);
})->throws(GaebWriteException::class, 'No VAT rate');

it('rejects garbage quantities, empty identifiers and bad dates at the builder', function () {
    expect(fn () => (new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789'))->billQty('01.0010', 'thirty'))
        ->toThrow(GaebWriteException::class, 'Invalid billed quantity');
    expect(fn () => new Invoice('', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789'))
        ->toThrow(GaebWriteException::class, 'invoiceNo');
    expect(fn () => new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', ''))
        ->toThrow(GaebWriteException::class, 'creatorTaxNo');
    expect(fn () => new Invoice('RE-1', 'Halloween', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789'))
        ->toThrow(GaebWriteException::class);
});

it('rejects payments missing required fields', function () {
    $invoice = new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789', vatPercent: '19');
    $invoice->billQty('01.0010', '1')
        ->addPayment(new Payment(total: '1190.00', totalVat: null, discountAmount: null, paymentDate: null, invoiceNo: 'RE-0'));

    contractDocument()->createInvoice($invoice);
})->throws(GaebWriteException::class, 'Payment is missing required field(s): totalVat, paymentDate');

/**
 * A doctored minimal X86 contract: one item (rNo 01.0010) under category 01,
 * with every piece healthy by default so a single fragment can be knocked
 * out per test to reach one specific writer throw.
 */
function minimalX86Contract(
    string $prjInfo = '<NamePrj>Test Project</NamePrj>',
    string $lblTx = '<LblTx><p><span>Category</span></p></LblTx>',
    string $boqInfoFields = "<Name>LV</Name>\n<LblBoQ>LV Label</LblBoQ>\n<OutlCompl>AllTxt</OutlCompl>",
    string $itemBody = "<QU>m3</QU>\n<UP>10.00</UP>\n<IT>10.00</IT>",
): string {
    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA86/3.3">
      <GAEBInfo>
        <Version>3.3</Version>
        <VersDate>2021-05</VersDate>
        <Date>2026-06-15</Date>
      </GAEBInfo>
      <PrjInfo>
        {$prjInfo}
      </PrjInfo>
      <Award>
        <DP>86</DP>
        <BoQ ID="B1">
          <BoQInfo>
            {$boqInfoFields}
          </BoQInfo>
          <BoQBody>
            <BoQCtgy ID="C01" RNoPart="01">
              {$lblTx}
              <BoQBody>
                <Itemlist>
                  <Item ID="I0010" RNoPart="0010">
                    {$itemBody}
                  </Item>
                </Itemlist>
              </BoQBody>
              <Totals><Total>10.00</Total></Totals>
            </BoQCtgy>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
}

function invoiceForDefect(): Invoice
{
    return new Invoice('RE-1', '2026-10-31', InvoiceType::Deduction, '2026-09-01', '2026-10-31', 'DE123456789', vatPercent: '19');
}

it('rejects a billed item with no unit (QU)', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(itemBody: "<UP>10.00</UP>\n<IT>10.00</IT>"));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'has no unit (QU)');

it('rejects billing a notApplicable item', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(itemBody: "<QU>m3</QU>\n<UP>10.00</UP>\n<IT>10.00</IT>\n<NotAppl>Yes</NotAppl>"));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'refer to notApplicable items');

it('rejects a billed item with no unit price (UP missing)', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(itemBody: "<QU>m3</QU>\n<IT>10.00</IT>"));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'no usable unit price in the contract: missing UP');

it('rejects a billed item with a garbage unit price', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(itemBody: "<QU>m3</QU>\n<UP>garbage</UP>\n<IT>10.00</IT>"));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'no usable unit price in the contract: Value "garbage"');

it('rejects a source category with no LblTx', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(lblTx: ''));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'has no LblTx');

it('rejects a source BoQInfo missing required fields', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(boqInfoFields: "<Name>LV</Name>\n<OutlCompl>AllTxt</OutlCompl>"));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'Source BoQInfo is missing required field(s) (Name, LblBoQ, OutlCompl)');

it('rejects a source project with no NamePrj', function () {
    $doc = GaebDocument::fromString(minimalX86Contract(prjInfo: ''));
    $invoice = invoiceForDefect()->billQty('01.0010', '1');

    $doc->createInvoice($invoice);
})->throws(GaebWriteException::class, 'Source project has no name');
