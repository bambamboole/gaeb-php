<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\ChangeOrderStatus;
use Bambamboole\Gaeb\Dto\InvoiceType;
use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParser;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Write\Invoice;

function nachtragDocument(): GaebDocument
{
    return GaebDocument::open(__DIR__.'/fixtures/nachtrag.x86');
}

function nachtragInvoice(): Invoice
{
    return new Invoice(
        invoiceNo: 'RE-2026-007',
        invoiceDate: '2026-11-30',
        type: InvoiceType::Deduction,
        servicePeriodStart: '2026-09-01',
        servicePeriodEnd: '2026-11-30',
        creatorTaxNo: 'DE123456789',
        vatPercent: '19',
        date: '2026-11-30',
    );
}

it('parses COInfo change orders from AwardInfo', function () {
    $gaeb = nachtragDocument()->file();

    expect($gaeb->award->changeOrders)->toHaveCount(2);

    $first = $gaeb->award->changeOrders[0];
    expect($first->no)->toBe(1)
        ->and($first->phase)->toBe('SupplAgree')
        ->and($first->status)->toBe(ChangeOrderStatus::Approved)
        ->and($first->initiator)->toBe('Owner')
        ->and($first->reason)->toContain('Bodenaustausch')
        ->and($first->reference)->toBe('LV Lagerhalle Nord')
        ->and($first->date)->toBe('2026-06-28');

    $second = $gaeb->award->changeOrders[1];
    expect($second->no)->toBe(2)
        ->and($second->phase)->toBe('SupplBid')
        ->and($second->status)->toBe(ChangeOrderStatus::Offered)
        ->and($second->initiator)->toBe('Contractor')
        ->and($second->reason)->toBeNull()
        ->and($second->reference)->toBeNull()
        ->and($second->date)->toBeNull();
});

it('parses CONo/COStatus on categories and items and leaves main-contract elements null', function () {
    $boq = nachtragDocument()->file()->boq;

    [$main, $nachtrag1, $nachtrag2] = $boq->categories;
    expect($main->changeOrderNo)->toBeNull()
        ->and($main->changeOrderStatus)->toBeNull()
        ->and($main->items[0]->changeOrderNo)->toBeNull()
        ->and($nachtrag1->changeOrderNo)->toBe(1)
        ->and($nachtrag1->changeOrderStatus)->toBe(ChangeOrderStatus::Approved)
        ->and($nachtrag1->items[0]->changeOrderNo)->toBe(1)
        ->and($nachtrag1->items[0]->changeOrderStatus)->toBe(ChangeOrderStatus::Approved)
        ->and($nachtrag2->changeOrderNo)->toBe(2)
        ->and($nachtrag2->items[0]->changeOrderStatus)->toBe(ChangeOrderStatus::Offered);
});

it('keeps change orders empty on files without Nachtrag data', function () {
    $gaeb = GaebDocument::open(__DIR__.'/fixtures/contract.x86')->file();

    expect($gaeb->award->changeOrders)->toBe([])
        ->and($gaeb->boq->changeOrderNo)->toBeNull()
        ->and($gaeb->boq->categories[0]->changeOrderNo)->toBeNull();
});

it('parses a BoQ-level CONo/COStatus pair and drops unknown status values leniently', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA86/3.3">
      <GAEBInfo><Version>3.3</Version></GAEBInfo>
      <Award>
        <DP>86</DP>
        <BoQ>
          <BoQInfo>
            <Name>Nachtrags-LV</Name>
            <CONo>3</CONo>
            <COStatus>Filed</COStatus>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item RNoPart="0010">
                <CONo>3</CONo>
                <COStatus>SomethingNew</COStatus>
                <Qty>1.000</Qty>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML);

    expect($gaeb->boq->changeOrderNo)->toBe(3)
        ->and($gaeb->boq->changeOrderStatus)->toBe(ChangeOrderStatus::Filed)
        ->and($gaeb->boq->items[0]->changeOrderNo)->toBe(3)
        ->and($gaeb->boq->items[0]->changeOrderStatus)->toBeNull();
});

it('bills an approved Nachtrag position and carries CONo/COStatus into the X89', function () {
    $invoice = nachtragInvoice();
    $invoice->billQty('01.0010', '30')->billQty('02.0010', '10');

    $doc = nachtragDocument()->createInvoice($invoice);

    expect($doc->validate())->toBe([]);

    $items = iterator_to_array($doc->file()->boq->allItems(), false);
    expect($items)->toHaveCount(2)
        ->and($items[0]->rNo)->toBe('01.0010')
        ->and($items[0]->changeOrderNo)->toBeNull()
        ->and($items[1]->rNo)->toBe('02.0010')
        ->and($items[1]->changeOrderNo)->toBe(1)
        ->and($items[1]->changeOrderStatus)->toBe(ChangeOrderStatus::Approved);
});

it('refuses to bill a Nachtrag position that is not approved', function () {
    $invoice = nachtragInvoice();
    $invoice->billQty('03.0010', '5');

    nachtragDocument()->createInvoice($invoice);
})->throws(GaebWriteException::class, '03.0010');
