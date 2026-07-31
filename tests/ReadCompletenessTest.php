<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\MarkupType;
use Bambamboole\Gaeb\GaebParser;

it('parses UP component labels, remarks, performance descriptions and markup items from an x83', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/markup.x83');
    $boq = $gaeb->boq;

    expect($boq->noUpComponents)->toBe(3)
        ->and($boq->upComponentLabels)->toBe([1 => 'Lohn', 2 => 'Material', 3 => 'Geraete'])
        ->and($boq->remarks)->toHaveCount(1)
        ->and($boq->remarks[0]->longText)->toBe('Alle Preise sind netto anzugeben.');

    $earthworks = $boq->categories[0];
    expect($earthworks->performanceDescriptions)->toHaveCount(1)
        ->and($earthworks->performanceDescriptions[0]->perfNo)->toBe('10')
        ->and($earthworks->performanceDescriptions[0]->label)->toBe('Bodenaushub allgemein')
        ->and($earthworks->performanceDescriptions[0]->longText)->toBe('Grundbeschreibung fuer alle Aushubpositionen.')
        ->and($earthworks->markupItems)->toHaveCount(2);

    [$allInCat, $subQty] = $earthworks->markupItems;
    expect($allInCat->rNo)->toBe('01.0090')
        ->and($allInCat->id)->toBe('M0090')
        ->and($allInCat->markupType)->toBe(MarkupType::AllInCategory)
        ->and($allInCat->shortText)->toBe('Zuschlag Kategorie')
        ->and($subQty->markupType)->toBe(MarkupType::ListedSubQuantities)
        ->and($subQty->subQuantities)->toHaveCount(1)
        ->and($subQty->subQuantities[0]->refItemId)->toBe('I0010')
        ->and($subQty->subQuantities[0]->qty)->toBeDecimal(50.0);

    [$digging, $hourly] = $earthworks->items;
    expect($digging->vat)->toBeDecimal(19.0)
        ->and($digging->id)->toBe('I0010')
        ->and($hourly->qtyToBeDetermined)->toBeTrue()
        ->and($hourly->qty)->toBeNull();

    expect($earthworks->notApplicable)->toBeFalse()
        ->and($boq->categories[1]->notApplicable)->toBeTrue();
});

it('parses UP components, discounts, VAT parts and priced markup items from an x84', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/components.x84');
    $totals = $gaeb->boq->totals;

    expect($totals->discountPercent)->toBeDecimal(3.0)
        ->and($totals->totalAfterDiscount)->toBeDecimal(533.5)
        ->and(array_map(strval(...), $totals->netUpComponents))->toBe([1 => '300.00', 2 => '233.50'])
        ->and($totals->vatParts)->toHaveCount(2)
        ->and($totals->vatParts[0]->percent)->toBeDecimal(19.0)
        ->and($totals->vatParts[0]->totalNetPart)->toBeDecimal(433.5)
        ->and($totals->vatParts[0]->vatAmount)->toBeDecimal(82.37)
        ->and($totals->vatParts[1]->percent)->toBeDecimal(7.0)
        ->and($totals->vatAmount)->toBeDecimal(89.37)
        ->and($totals->totalGross)->toBeDecimal(622.87);

    $category = $gaeb->boq->categories[0];
    expect($category->totals->total)->toBeDecimal(550.0);

    [$priced, $notOffered] = $category->items;
    expect(array_map(strval(...), $priced->upComponents))->toBe([1 => '30.000', 2 => '20.000'])
        ->and($priced->discountPercent)->toBeDecimal(5.0)
        ->and($priced->unitPrice)->toBeDecimal(50.0)
        ->and($notOffered->notOffered)->toBeTrue()
        ->and($notOffered->unitPrice)->toBeNull();

    $markup = $category->markupItems[0];
    expect($markup->markupPercent)->toBeDecimal(10.0)
        ->and($markup->markupTotal)->toBeDecimal(50.0)
        ->and($markup->totalPrice)->toBeDecimal(50.0);
});

it('parses category totals from an x86 contract', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/contract.x86');

    expect($gaeb->boq->categories[0]->totals->total)->toBeDecimal(3475.0);
});
