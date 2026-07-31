<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\InvoiceType;
use Bambamboole\Gaeb\Dto\Payment;
use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParser;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Write\Invoice;
use Brick\Math\BigDecimal;

function supportingInvoice(): Invoice
{
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
    $invoice->billQty('01.0010', '30')->billQty('01.0020', '1');

    return $invoice;
}

it('creates a schema-valid X89B supporting document from the X86 contract', function () {
    // Payments belong to the e-invoice, not the attachment: even when the
    // shared Invoice carries them (for the X89 twin), the X89B omits them.
    $invoice = supportingInvoice()->addPayment(new Payment(
        total: BigDecimal::of('1190.00'),
        totalVat: BigDecimal::of('190.00'),
        discountAmount: null,
        paymentDate: '2026-10-05',
        invoiceNo: 'RE-2026-000',
    ));

    $doc = GaebDocument::fromString(fixture('contract.x86'))->createSupportingDocument($invoice);
    $xml = $doc->toString();

    expect($doc->validate())->toBe([])
        ->and($xml)->toContain('http://www.gaeb.de/GAEB_DA_XML/DA89B/3.3')
        ->and($xml)->toContain('<DP>89B</DP>')
        ->and($xml)->toContain('<RefInvoiceNo>RE-2026-001</RefInvoiceNo>')
        ->and($xml)->not->toContain('<PaymentMade>')
        ->and($xml)->not->toContain('<InvoiceShare>')
        ->and($xml)->not->toContain('<InvoiceRecipient>')
        ->and($xml)->not->toContain('<InvoiceNo>');
});

it('parses its own X89B output: phase 89, dp 89B, RefInvoiceNo as invoiceNo', function () {
    $doc = GaebDocument::fromString(fixture('contract.x86'))->createSupportingDocument(supportingInvoice());

    $gaeb = GaebParser::fromString($doc->toString());
    expect($doc->phase())->toBe(89)
        ->and($gaeb->info->phase)->toBe(89)
        ->and($gaeb->info->dp)->toBe('89B')
        ->and($gaeb->invoice->invoiceNo)->toBe('RE-2026-001')
        ->and($gaeb->invoice->payments)->toBe([])
        ->and($gaeb->invoice->totalGross)->toBeNull()
        ->and($gaeb->boq->totals->total)->toBeDecimal('2565.00')
        ->and($gaeb->boq->totals->totalGross)->toBeDecimal('3052.35');
});

it('enforces the same billing rules as createInvoice', function () {
    $invoice = supportingInvoice()->billQty('99.9999', '1');

    GaebDocument::fromString(fixture('contract.x86'))->createSupportingDocument($invoice);
})->throws(GaebWriteException::class, 'Unknown rNo(s) referenced in invoice: 99.9999');

it('throws when the source phase is not X86', function () {
    GaebDocument::fromString(fixture('boq.x83'))->createSupportingDocument(supportingInvoice());
})->throws(GaebWriteException::class, 'createSupportingDocument requires an X86 source, got X83');

it('reads the supporting.x89b fixture', function () {
    $gaeb = GaebParser::fromString(fixture('supporting.x89b'));

    expect($gaeb->info->phase)->toBe(89)
        ->and($gaeb->info->dp)->toBe('89B')
        ->and($gaeb->invoice->invoiceNo)->toBe('RE-2026-006')
        ->and($gaeb->invoice->servicePeriodStart)->toBe('2026-09-01')
        ->and($gaeb->invoice->payments)->toBe([])
        ->and(iterator_to_array($gaeb->boq->allItems(), false))->not->toBe([]);
});
