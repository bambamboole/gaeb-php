<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebParser;

it('parses nonstandard element spellings and missing required parts leniently', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/nonconforming.x83');

    expect($gaeb->project->name)->toBe('Altbau Sanierung')
        ->and($gaeb->boq->label)->toBe('LV Sanierung')
        ->and($gaeb->boq->items)->toHaveCount(1)
        ->and($gaeb->boq->items[0]->rNo)->toBe('0010')
        ->and($gaeb->boq->items[0]->shortText)->toBeNull();
});
