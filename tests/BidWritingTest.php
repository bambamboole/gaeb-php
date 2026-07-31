<?php declare(strict_types=1);

use Bambamboole\GaebParser\Dto\Contractor;
use Bambamboole\GaebParser\GaebDocument;
use Bambamboole\GaebParser\GaebParser;
use Bambamboole\GaebParser\GaebWriteException;
use Bambamboole\GaebParser\Write\Bid;

function makeBid(): Bid
{
    return new Bid(new Contractor(
        name: 'Muster Bau GmbH',
        street: 'Handwerkerweg 1',
        zip: '53179',
        city: 'Bonn',
        email: 'bau@example.test',
        phone: null,
    ));
}

function priceAll(GaebDocument $doc, Bid $bid, float $up = 10.0): void
{
    foreach ($doc->file()->boq->allItems() as $item) {
        if (! $item->notApplicable) {
            $bid->setUnitPrice($item->rNo, $up);
        }
    }
}

it('creates a schema-valid x84 bid from an x83 tender', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid);
    $bid->fillGap('01.02.0010', 2, 'Fabrikat Musterrohr');
    $bid->setComment('01.02.0010', 'Lieferzeit 6 Wochen');

    $x84 = $doc->createBid($bid);

    expect($x84->validate())->toBe([]);
});

it('produces a bid the parser reads back with matching structure and prices', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid, 10.0);

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());

    expect($parsed->info->phase)->toBe(84)
        ->and($parsed->project->currency)->toBe('EUR');

    $sourceRNos = [];
    foreach ($doc->file()->boq->allItems() as $item) {
        if (! $item->notApplicable) {
            $sourceRNos[] = $item->rNo;
        }
    }
    $bidItems = iterator_to_array($parsed->boq->allItems(), false);
    expect(array_map(fn ($i) => $i->rNo, $bidItems))->toBe($sourceRNos);
    foreach ($bidItems as $item) {
        expect($item->unitPrice)->toBe(10.0);
    }
});

it('computes the total with classification exclusions', function () {
    // boq.x83 items priced at 10.0 (round(qty*10, 2), lump-sum -> round(10, 2)):
    //   01.02.0010 qty 100    -> 1000.00  (included)
    //   01.02.0020 lump sum   ->   10.00  (included)
    //   03.0030    qty 5, Provis=WithTotal -> 50.00  (included; only WithoutTotal is excluded)
    //   03.0040    qty 10, ALNGroupNo 1 ALNSerNo 1 -> 100.00 (included; serial 1 = Grundausfuehrung)
    //   03.0040.1  qty 10, ALNGroupNo 1 ALNSerNo 2 -> 100.00 (EXCLUDED; alternative, serial != 1)
    //   03.0050    qty 20, HourIt=Yes -> 200.00 (included; hourly work counts)
    // Total = 1000 + 10 + 50 + 100 + 200 = 1360.00
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid, 10.0);

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());

    expect($parsed->boq->totals->total)->toBe(1360.0);
});

it('round-trips gap fills and comments', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid);
    $bid->fillGap('01.02.0010', 2, 'Fabrikat Musterrohr');
    $bid->setComment('01.02.0010', 'Lieferzeit 6 Wochen');

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());
    $items = [];
    foreach ($parsed->boq->allItems() as $item) {
        $items[$item->rNo] = $item;
    }

    $complements = $items['01.02.0010']->textComplements;
    expect($complements)->toHaveCount(1)
        ->and($complements[0]->markLabel)->toBe(2)
        ->and($complements[0]->body)->toBe('Fabrikat Musterrohr')
        ->and($items['01.02.0010']->bidderComment)->toBe('Lieferzeit 6 Wochen');
});

it('throws when priceable items are missing prices', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    $bid->setUnitPrice('01.02.0010', 12.5);

    $doc->createBid($bid);
})->throws(GaebWriteException::class, '01.02.0020');

it('throws on unknown rNo', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid);
    $bid->setUnitPrice('99.9999', 1.0);

    $doc->createBid($bid);
})->throws(GaebWriteException::class, '99.9999');
