<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('parses a real-world GAEB sample', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/sample.x84');

    expect($gaeb->info->phase)->toBeIn([81, 82, 83, 84, 85, 86])
        ->and($gaeb->boq)->not->toBeNull()
        ->and(iterator_to_array($gaeb->boq->allItems(), false))->not->toBeEmpty();

    foreach ($gaeb->boq->allItems() as $item) {
        expect($item->rNo)->not->toBe('');
    }
});
