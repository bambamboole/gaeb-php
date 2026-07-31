<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParser;

it('parses an X80 service description', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/description.x80');

    expect($gaeb->info->phase)->toBe(80)
        ->and($gaeb->project->name)->toBe('Musterprojekt Lagerhalle Nord')
        ->and($gaeb->boq->label)->toBe('Lagerhalle Nord - Leistungsbeschreibung')
        ->and($gaeb->boq->currency)->toBe('EUR');

    $items = iterator_to_array($gaeb->boq->allItems(), false);
    expect($items)->toHaveCount(2)
        ->and($items[0]->rNo)->toBe('01.0010')
        ->and($items[0]->qty)->toBe(120.0)
        ->and($items[0]->unitPrice)->toBeNull()
        ->and($items[1]->rNo)->toBe('01.0020')
        ->and($items[1]->qty)->toBeNull()
        ->and($items[1]->unit)->toBe('m2');
});

it('parses an X87 order confirmation like its X86 contract', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/confirmation.x87');

    expect($gaeb->info->phase)->toBe(87)
        ->and($gaeb->owner->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->award->contractNo)->toBe('A-2026-042')
        ->and($gaeb->boq->totals->total)->toBe(3475.00);
});

it('resolves the Leistungsverzeichnis XSD family for X80 and X87 in validate()', function (string $fixture) {
    expect(GaebDocument::open(__DIR__.'/fixtures/'.$fixture)->validate())->toBe([]);
})->with(['description.x80', 'confirmation.x87']);
