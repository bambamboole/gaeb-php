<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebParser;

it('returns null boq when the file has none', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->boq)->toBeNull();
});

it('parses the category tree', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;

    expect($boq->label)->toBe('Leistungsverzeichnis Testhalle')
        ->and($boq->currency)->toBe('EUR')
        ->and($boq->categories)->toHaveCount(1);

    $erdarbeiten = $boq->categories[0];
    expect($erdarbeiten->rNoPart)->toBe('01')
        ->and($erdarbeiten->label)->toBe('Erdarbeiten')
        ->and($erdarbeiten->categories)->toHaveCount(1);

    $aushub = $erdarbeiten->categories[0];
    expect($aushub->rNoPart)->toBe('02')
        ->and($aushub->label)->toBe('Aushub')
        ->and($aushub->items)->toHaveCount(2);
});

it('parses items with quantities, texts and flags', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;
    [$first, $second] = $boq->categories[0]->categories[0]->items;

    expect($first->rNo)->toBe('01.02.0010')
        ->and($first->rNoPart)->toBe('0010')
        ->and($first->qty)->toBe(100.0)
        ->and($first->unit)->toBe('m3')
        ->and($first->shortText)->toBe('Boden loesen')
        ->and($first->longText)->toBe("Boden loesen und lagern.\nBodenklasse 3-5.")
        ->and($first->descriptionXml)->toContain('<DetailTxt>')
        ->and($first->unitPrice)->toBeNull()
        ->and($first->lumpSum)->toBeFalse();

    expect($second->rNo)->toBe('01.02.0020')
        ->and($second->qty)->toBeNull()
        ->and($second->unit)->toBe('psch')
        ->and($second->shortText)->toBe('Baustelle einrichten')
        ->and($second->longText)->toBeNull()
        ->and($second->lumpSum)->toBeTrue();
});
