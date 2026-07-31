<?php declare(strict_types=1);

use Bambamboole\GaebParser\Dto\InvoiceType;
use Bambamboole\GaebParser\Dto\Payment;
use Bambamboole\GaebParser\Dto\SettlementType;
use Bambamboole\GaebParser\GaebDocument;
use Bambamboole\GaebParser\Write\Invoice;

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
        ->and($gaeb->boq->total)->toBe(2565.00)
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
        ->and($gaeb->boq->total)->toBe(568.75);
});
