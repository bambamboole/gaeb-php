<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\WarrantyUnit;
use Bambamboole\Gaeb\GaebParser;

it('parses the contractor party from an X84 bid', function () {
    $gaeb = GaebParser::fromString(fixture('realistic.x84'));

    expect($gaeb->contractor)->not->toBeNull()
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->contractor->street)->toBe('Baustrasse 1')
        ->and($gaeb->contractor->zip)->toBe('12345')
        ->and($gaeb->contractor->city)->toBe('Musterstadt')
        ->and($gaeb->contractor->phone)->toBeNull()
        ->and($gaeb->contractor->email)->toBeNull()
        ->and($gaeb->owner)->toBeNull();

    $priced = GaebParser::fromString(fixture('priced.x84'));
    expect($priced->contractor)->not->toBeNull()
        ->and($priced->contractor->name)->toBe('Musterbau GmbH')
        ->and($priced->owner)->toBeNull();
});

it('yields null parties when OWN and CTR are absent', function () {
    $gaeb = GaebParser::fromString(fixture('boq.x83'));

    expect($gaeb->owner)->toBeNull()
        ->and($gaeb->contractor)->toBeNull();
});

it('parses a party with an empty Address leniently', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA86/3.3">
          <Award>
            <DP>86</DP>
            <OWN><Address/></OWN>
            <CTR/>
          </Award>
        </GAEB>
        XML);

    expect($gaeb->owner)->not->toBeNull()
        ->and($gaeb->owner->name)->toBeNull()
        ->and($gaeb->contractor)->not->toBeNull()
        ->and($gaeb->contractor->city)->toBeNull();
});

it('parses award data from AwardInfo', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA86/3.3">
          <Award>
            <DP>86</DP>
            <AwardInfo>
              <Cur>EUR</Cur>
              <BidDate>2026-05-04</BidDate>
              <CnstStart>2026-09-01</CnstStart>
              <CnstEnd>2027-03-31</CnstEnd>
              <ContrNo>A-2026-042</ContrNo>
              <ContrDate>2026-06-15</ContrDate>
              <WarrDur>5</WarrDur>
              <WarrUnit>Years</WarrUnit>
              <WarrEnd>2032-03-31</WarrEnd>
            </AwardInfo>
          </Award>
        </GAEB>
        XML);

    expect($gaeb->award)->not->toBeNull()
        ->and($gaeb->award->contractNo)->toBe('A-2026-042')
        ->and($gaeb->award->contractDate)->toBe('2026-06-15')
        ->and($gaeb->award->bidDate)->toBe('2026-05-04')
        ->and($gaeb->award->constructionStart)->toBe('2026-09-01')
        ->and($gaeb->award->constructionEnd)->toBe('2027-03-31')
        ->and($gaeb->award->warrantyDuration)->toBe(5)
        ->and($gaeb->award->warrantyUnit)->toBe(WarrantyUnit::Years)
        ->and($gaeb->award->warrantyEnd)->toBe('2032-03-31');
});

it('yields null award when AwardInfo is absent and all-null fields when it is sparse', function () {
    $none = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
          <Award><DP>83</DP></Award>
        </GAEB>
        XML);
    expect($none->award)->toBeNull();

    $sparse = GaebParser::fromString(fixture('realistic.x84'));
    expect($sparse->award)->not->toBeNull()
        ->and($sparse->award->contractNo)->toBeNull()
        ->and($sparse->award->warrantyUnit)->toBeNull();
});

it('drops an unknown WarrUnit value leniently', function () {
    $gaeb = GaebParser::fromString(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA86/3.3">
          <Award>
            <DP>86</DP>
            <AwardInfo><WarrDur>24</WarrDur><WarrUnit>Weeks</WarrUnit></AwardInfo>
          </Award>
        </GAEB>
        XML);

    expect($gaeb->award->warrantyDuration)->toBe(24)
        ->and($gaeb->award->warrantyUnit)->toBeNull();
});

it('parses a full X86 contract file', function () {
    $gaeb = GaebParser::fromString(fixture('contract.x86'));

    expect($gaeb->info->phase)->toBe(86)
        ->and($gaeb->owner->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->owner->street)->toBe('Rathausplatz 1')
        ->and($gaeb->owner->zip)->toBe('12345')
        ->and($gaeb->owner->city)->toBe('Musterstadt')
        ->and($gaeb->owner->phone)->toBe('+49 30 1234560')
        ->and($gaeb->owner->email)->toBe('vergabe@stadtwerke-musterstadt.example')
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->contractor->email)->toBe('info@musterbau.example');

    expect($gaeb->award->contractNo)->toBe('A-2026-042')
        ->and($gaeb->award->contractDate)->toBe('2026-06-15')
        ->and($gaeb->award->bidDate)->toBe('2026-05-04')
        ->and($gaeb->award->constructionStart)->toBe('2026-09-01')
        ->and($gaeb->award->constructionEnd)->toBe('2027-03-31')
        ->and($gaeb->award->warrantyDuration)->toBe(5)
        ->and($gaeb->award->warrantyUnit)->toBe(WarrantyUnit::Years)
        ->and($gaeb->award->warrantyEnd)->toBe('2032-03-31');
});

it('parses the awarded BoQ of an X86 file through the existing BoQ model', function () {
    $gaeb = GaebParser::fromString(fixture('contract.x86'));

    expect($gaeb->boq->label)->toBe('Lagerhalle Nord - Auftrags-LV')
        ->and($gaeb->boq->currency)->toBe('EUR')
        ->and($gaeb->boq->totals->total)->toBeDecimal(3475.00)
        ->and($gaeb->boq->categories)->toHaveCount(1);

    [$first, $second] = $gaeb->boq->categories[0]->items;
    expect($first->rNo)->toBe('01.0010')
        ->and($first->qty)->toBeDecimal(50.0)
        ->and($first->unit)->toBe('m3')
        ->and($first->unitPrice)->toBeDecimal(45.50)
        ->and($first->totalPrice)->toBeDecimal(2275.00)
        ->and($first->shortText)->toBe('Boden loesen')
        ->and($second->lumpSum)->toBeTrue()
        ->and($second->totalPrice)->toBeDecimal(1200.00);
});
