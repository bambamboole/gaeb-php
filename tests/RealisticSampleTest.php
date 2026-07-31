<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\TextComplementKind;
use Bambamboole\Gaeb\GaebParser;

it('parses the self-authored realistic sample fixture', function () {
    $gaeb = GaebParser::fromString(fixture('realistic.x84'));

    expect($gaeb->project->name)->toBe('Musterprojekt Lagerhalle Nord')
        ->and($gaeb->project->currency)->toBe('EUR')
        ->and($gaeb->boq->label)->toBe('LV Lagerhalle Nord')
        ->and($gaeb->boq->currency)->toBe('EUR')
        ->and($gaeb->boq->totals->total)->toBeDecimal(9649.00)
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
    expect($fundament->unitPrice)->toBeDecimal(185.00)
        ->and($fundament->totalPrice)->toBeDecimal(5550.00);

    $noDescription = $items[3];
    expect($noDescription->shortText)->toBeNull()
        ->and($noDescription->longText)->toBeNull()
        ->and($noDescription->descriptionXml)->toBeNull()
        ->and($noDescription->lumpSum)->toBeFalse()
        ->and($noDescription->textComplements)->toBe([])
        ->and($noDescription->bidderComment)->toBeNull()
        ->and($noDescription->subDescriptions)->toBe([]);
});

it('parses bid data on the bid-submission item: a filled gap, bidder comments and a priced sub-description', function () {
    $items = iterator_to_array(GaebParser::fromString(fixture('realistic.x84'))->boq->allItems(), false);
    $item = $items[0];

    expect($item->rNo)->toBe('01.0010')
        ->and($item->longText)->toBe('Fabrikat: Muster GmbH, Typ ABC-500')
        ->and($item->textComplements)->toHaveCount(1);

    $gap = $item->textComplements[0];
    expect($gap->markLabel)->toBe(1)
        ->and($gap->kind)->toBe(TextComplementKind::Bidder)
        // X84's restricted tgTextComplement drops ComplCaption and ComplTail
        // entirely (verified with xmllint against
        // GAEB_DA_XML_84_3.3_2021-05.xsd) — only ComplBody survives on the
        // bid-submission side.
        ->and($gap->caption)->toBeNull()
        ->and($gap->body)->toBe('Fabrikat: Muster GmbH, Typ ABC-500')
        ->and($gap->tail)->toBeNull();

    expect($item->bidderComment)->toBe("Lieferzeit 4 Wochen ab Auftrag\nAlternative Ausfuehrung auf Anfrage");

    expect($item->subDescriptions)->toHaveCount(1);
    $sub = $item->subDescriptions[0];
    expect($sub->subDNo)->toBe('1')
        ->and($sub->qty)->toBeDecimal(5.0)
        ->and($sub->unitPrice)->toBeDecimal(18.75)
        // X84's restricted tgSubDescr has no QU element at all (verified with
        // xmllint) — the bid item's own QU already fixes the unit, so
        // sub-descriptions only add UP/UPComp*.
        ->and($sub->unit)->toBeNull()
        // Description is optional in X84's tgSubDescr and, if present, its
        // Text/<p> content model is restricted to TextComplement only (no
        // span/text) — omitted here, so both texts stay null.
        ->and($sub->shortText)->toBeNull()
        ->and($sub->longText)->toBeNull();
});

it('parses the totals breakdown', function () {
    $totals = GaebParser::fromString(fixture('realistic.x84'))->boq->totals;

    expect($totals->total)->toBeDecimal(9649.00)
        ->and($totals->discountPercent)->toBeDecimal(5.0)
        ->and($totals->discountAmount)->toBeNull()
        ->and($totals->totalAfterDiscount)->toBeDecimal(9166.55)
        ->and($totals->vat)->toBeDecimal(19.00)
        ->and($totals->vatAmount)->toBeDecimal(1741.64)
        ->and($totals->totalNet)->toBeDecimal(9166.55)
        ->and($totals->totalGross)->toBeDecimal(10908.19);
});
