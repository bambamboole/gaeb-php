<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('parses the self-authored realistic sample fixture', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/realistic.x84');

    expect($gaeb->project->name)->toBe('Musterprojekt Lagerhalle Nord')
        ->and($gaeb->project->currency)->toBe('EUR')
        ->and($gaeb->boq->label)->toBe('Lagerhalle Nord - Leistungsverzeichnis')
        ->and($gaeb->boq->currency)->toBe('EUR')
        ->and($gaeb->boq->total)->toBe(9649.00)
        ->and($gaeb->info->program)->toBe('Fable Sample Suite 1.0');

    $items = iterator_to_array($gaeb->boq->allItems(), false);
    expect($items)->toHaveCount(4)
        ->and(array_map(fn ($i) => $i->rNo, $items))->toBe([
            '01.0010',
            '01.0010.1',
            '02.01.0010',
            '02.01.0020',
        ]);

    $variant = $items[1];
    expect($variant->rNo)->toBe('01.0010.1')
        ->and($variant->rNoPart)->toBe('0010');

    $fundament = $items[2];
    expect($fundament->unitPrice)->toBe(185.00)
        ->and($fundament->totalPrice)->toBe(5550.00);

    $noDescription = $items[3];
    expect($noDescription->shortText)->toBeNull()
        ->and($noDescription->longText)->toBeNull()
        ->and($noDescription->descriptionXml)->toBeNull()
        ->and($noDescription->lumpSum)->toBeTrue();
});
