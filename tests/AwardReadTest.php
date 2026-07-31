<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('parses the contractor party from an X84 bid', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/realistic.x84');

    expect($gaeb->contractor)->not->toBeNull()
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->contractor->street)->toBe('Baustrasse 1')
        ->and($gaeb->contractor->zip)->toBe('12345')
        ->and($gaeb->contractor->city)->toBe('Musterstadt')
        ->and($gaeb->contractor->phone)->toBeNull()
        ->and($gaeb->contractor->email)->toBeNull()
        ->and($gaeb->owner)->toBeNull();
});

it('yields null parties when OWN and CTR are absent', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83');

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
