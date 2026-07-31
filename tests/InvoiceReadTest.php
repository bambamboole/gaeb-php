<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\InvoiceType;
use Bambamboole\Gaeb\Dto\SettlementType;
use Bambamboole\Gaeb\GaebParser;

it('parses a full X89 invoice into the InvoiceData aggregate', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA89/3.3">
          <Invoice>
            <DP>89</DP>
            <OWN><Address><Name1>Stadtwerke Musterstadt</Name1></Address></OWN>
            <CTR><Address><Name1>Musterbau GmbH</Name1></Address></CTR>
            <BoQ ID="B1">
              <BoQBody>
                <Itemlist>
                  <Item ID="I1" RNoPart="0010">
                    <BillQty>30.000</BillQty>
                    <QU>m3</QU>
                    <UP>45.500</UP>
                    <IT>1365.00</IT>
                  </Item>
                </Itemlist>
              </BoQBody>
            </BoQ>
            <InvoiceHeader>
              <InvoiceNo>RE-2026-007</InvoiceNo>
              <InvoiceDate>2026-10-31</InvoiceDate>
              <InvoiceType>deduction</InvoiceType>
              <SettlementType>accumulated</SettlementType>
              <SequentialNo>2</SequentialNo>
              <ServiceProvisionStartDate>2026-09-01</ServiceProvisionStartDate>
              <ServiceProvisionEndDate>2026-10-31</ServiceProvisionEndDate>
            </InvoiceHeader>
            <InvoiceCreator>
              <Address><Name1>Musterbau GmbH</Name1><Street>Baustrasse 1</Street></Address>
              <TaxNo>DE123456789</TaxNo>
            </InvoiceCreator>
            <InvoiceRecipient>
              <Address><Name1>Stadtwerke Musterstadt</Name1></Address>
            </InvoiceRecipient>
            <PaymentMade>
              <TotalVAT>190.00</TotalVAT>
              <Total>1190.00</Total>
              <PaymentDate>2026-10-05</PaymentDate>
              <InvoiceNo>RE-2026-006</InvoiceNo>
            </PaymentMade>
            <TotalGross>3052.35</TotalGross>
          </Invoice>
        </GAEB>
        XML);

    expect($gaeb->info->phase)->toBe(89)
        ->and($gaeb->invoice)->not->toBeNull()
        ->and($gaeb->invoice->invoiceNo)->toBe('RE-2026-007')
        ->and($gaeb->invoice->invoiceDate)->toBe('2026-10-31')
        ->and($gaeb->invoice->type)->toBe(InvoiceType::Deduction)
        ->and($gaeb->invoice->creditNote)->toBeFalse()
        ->and($gaeb->invoice->settlementType)->toBe(SettlementType::Accumulated)
        ->and($gaeb->invoice->sequentialNo)->toBe(2)
        ->and($gaeb->invoice->servicePeriodStart)->toBe('2026-09-01')
        ->and($gaeb->invoice->servicePeriodEnd)->toBe('2026-10-31')
        ->and($gaeb->invoice->creator->name)->toBe('Musterbau GmbH')
        ->and($gaeb->invoice->creator->taxNo)->toBe('DE123456789')
        ->and($gaeb->invoice->recipient->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->invoice->recipient->taxNo)->toBeNull()
        ->and($gaeb->invoice->totalGross)->toBe(3052.35);

    expect($gaeb->invoice->payments)->toHaveCount(1)
        ->and($gaeb->invoice->payments[0]->total)->toBe('1190.00')
        ->and($gaeb->invoice->payments[0]->totalVat)->toBe('190.00')
        ->and($gaeb->invoice->payments[0]->discountAmount)->toBeNull()
        ->and($gaeb->invoice->payments[0]->paymentDate)->toBe('2026-10-05')
        ->and($gaeb->invoice->payments[0]->invoiceNo)->toBe('RE-2026-006');

    expect($gaeb->owner->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->boq)->not->toBeNull()
        ->and($gaeb->boq->items[0]->billedQty)->toBe(30.0)
        ->and($gaeb->boq->items[0]->unitPrice)->toBe(45.5)
        ->and($gaeb->award)->toBeNull();
});

it('parses unknown invoice enums and sparse headers leniently', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA89/3.3">
          <Invoice>
            <DP>89</DP>
            <InvoiceHeader>
              <InvoiceType>Abschlagsrechnung</InvoiceType>
              <SettlementType>weekly</SettlementType>
            </InvoiceHeader>
          </Invoice>
        </GAEB>
        XML);

    expect($gaeb->invoice)->not->toBeNull()
        ->and($gaeb->invoice->invoiceNo)->toBeNull()
        ->and($gaeb->invoice->type)->toBeNull()
        ->and($gaeb->invoice->settlementType)->toBeNull()
        ->and($gaeb->invoice->creditNote)->toBeFalse()
        ->and($gaeb->invoice->creator)->toBeNull()
        ->and($gaeb->invoice->payments)->toBe([])
        ->and($gaeb->invoice->totalGross)->toBeNull();
});

it('keeps invoice null for award documents', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/contract.x86');

    expect($gaeb->invoice)->toBeNull()
        ->and($gaeb->boq->items ?? null)->not->toBeNull();
});

it('parses the invoice.x89 fixture end to end', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/invoice.x89');

    expect($gaeb->info->phase)->toBe(89)
        ->and($gaeb->invoice->invoiceNo)->toBe('RE-2026-007')
        ->and($gaeb->invoice->type)->toBe(InvoiceType::Deduction)
        ->and($gaeb->invoice->settlementType)->toBe(SettlementType::Accumulated)
        ->and($gaeb->invoice->creator->taxNo)->toBe('DE123456789')
        ->and($gaeb->invoice->payments)->toHaveCount(1)
        ->and($gaeb->invoice->totalGross)->toBe(3052.35)
        ->and($gaeb->boq->total)->toBe(2565.00)
        ->and($gaeb->boq->totals->totalGross)->toBe(3052.35);

    $items = iterator_to_array($gaeb->boq->allItems(), false);
    expect($items)->toHaveCount(2)
        ->and($items[0]->rNo)->toBe('01.0010')
        ->and($items[0]->billedQty)->toBe(30.0)
        ->and($items[0]->totalPrice)->toBe(1365.00)
        ->and($items[1]->billedQty)->toBe(1.0);
});
