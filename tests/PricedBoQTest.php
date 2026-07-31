<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebParser;

it('parses prices and totals from an x84 file', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/priced.x84');

    expect($gaeb->info->phase)->toBe(84)
        ->and($gaeb->boq->total)->toBe(1450.00)
        ->and($gaeb->boq->categories)->toHaveCount(2);

    $item = $gaeb->boq->categories[0]->items[0];
    expect($item->unitPrice)->toBe(12.50)
        ->and($item->totalPrice)->toBe(1250.00);
});

it('iterates all items flattened with resolved position numbers', function () {
    $items = iterator_to_array(
        GaebParser::fromFile(__DIR__.'/fixtures/priced.x84')->boq->allItems(),
        false,
    );

    expect($items)->toHaveCount(2)
        ->and(array_map(fn ($i) => $i->rNo, $items))->toBe(['01.0010', '90.0010']);
});
