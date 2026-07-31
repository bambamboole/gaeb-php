<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\Contractor;
use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParser;
use Bambamboole\Gaeb\GaebWriteException;
use Bambamboole\Gaeb\Write\Bid;

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

it('excludes notApplicable items from the bid and its totals', function () {
    // boq.x83 can't carry NotAppl (X83 drops the element per an earlier
    // round's schema check), so this builds a minimal inline DA83 source.
    // Reading is lenient, so it need not be schema-valid itself.
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-NA</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-NA</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>2.000</Qty>
                <QU>m</QU>
              </Item>
              <Item ID="I2" RNoPart="0020">
                <NotAppl>Yes</NotAppl>
                <Qty>5.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0010', 7.5);

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());
    $items = iterator_to_array($parsed->boq->allItems(), false);

    expect(array_map(fn ($i) => $i->rNo, $items))->toBe(['0010'])
        ->and($parsed->boq->totals->total)->toBe(15.0);
});

it('writes an explicitly supplied bid date instead of today', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = new Bid(new Contractor(
        name: 'Muster Bau GmbH',
        street: 'Handwerkerweg 1',
        zip: '53179',
        city: 'Bonn',
        email: 'bau@example.test',
        phone: null,
    ), date: '2020-01-02');
    priceAll($doc, $bid);

    expect($doc->createBid($bid)->toString())->toContain('<Date>2020-01-02</Date>');
});

it('stamps a custom progSystem and defaults to the package name', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');

    $bid = makeBid();
    priceAll($doc, $bid);
    expect($doc->createBid($bid)->toString())->toContain('<ProgSystem>bambamboole/gaeb</ProgSystem>');

    $custom = new Bid($bid->contractor, progSystem: 'my-erp 1.0');
    priceAll($doc, $custom);
    expect($doc->createBid($custom)->file()->info->program)->toBe('my-erp 1.0');
});

it('throws when the bid contains no items', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-NA</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-NA</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <NotAppl>Yes</NotAppl>
                <Qty>5.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();

    $doc->createBid($bid);
})->throws(GaebWriteException::class, 'Bid contains no items');

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

it('rounds the unit price before computing IT so emitted UP x Qty == IT exactly', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid);
    $bid->setUnitPrice('01.02.0010', 12.3456); // qty 100.000

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());
    $item = null;
    foreach ($parsed->boq->allItems() as $candidate) {
        if ($candidate->rNo === '01.02.0010') {
            $item = $candidate;
        }
    }

    expect($item)->not->toBeNull()
        ->and($item->unitPrice)->toBe(12.346)
        ->and(round($item->qty * $item->unitPrice, 2))->toBe($item->totalPrice);
});

it('throws when a priced/commented/gap-filled rNo resolves to a notApplicable item', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-NA</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-NA</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <NotAppl>Yes</NotAppl>
                <Qty>5.000</Qty>
                <QU>m</QU>
              </Item>
              <Item ID="I2" RNoPart="0020">
                <Qty>2.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0020', 1.0);
    $bid->setComment('0010', 'this should not be allowed');

    $doc->createBid($bid);
})->throws(GaebWriteException::class, '0010');

it('throws when a gap fill markLabel has no matching Bidder complement on the source item', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');
    $bid = makeBid();
    priceAll($doc, $bid);
    $bid->fillGap('01.02.0010', 99, 'phantom');

    $doc->createBid($bid);
})->throws(GaebWriteException::class, '01.02.0010 markLabel 99');

it('throws when no currency can be found anywhere in the source', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-NOCUR</NamePrj></PrjInfo>
      <Award>
        <DP>83</DP>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-NOCUR</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>2.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0010', 1.0);

    $doc->createBid($bid);
})->throws(GaebWriteException::class, 'no currency found in source');

it('succeeds without a source currency when Bid::$currency is set', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-NOCUR</NamePrj></PrjInfo>
      <Award>
        <DP>83</DP>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-NOCUR</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>2.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = new Bid(new Contractor(
        name: 'Muster Bau GmbH',
        street: 'Handwerkerweg 1',
        zip: '53179',
        city: 'Bonn',
        email: 'bau@example.test',
        phone: null,
    ), currency: 'EUR');
    $bid->setUnitPrice('0010', 1.0);

    $parsed = GaebParser::fromString($doc->createBid($bid)->toString());

    expect($parsed->project->currency)->toBe('EUR');
});

it('throws when the source phase is not X81-X83', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/priced.x84');
    $bid = makeBid();

    $doc->createBid($bid);
})->throws(GaebWriteException::class, 'createBid requires an X81–X83 source, got X84');

it('throws when the Bid date is not in YYYY-MM-DD format', function () {
    new Bid(new Contractor(
        name: 'Muster Bau GmbH',
        street: 'Handwerkerweg 1',
        zip: '53179',
        city: 'Bonn',
        email: 'bau@example.test',
        phone: null,
    ), date: '02/01/2020');
})->throws(GaebWriteException::class, 'Invalid date (expected YYYY-MM-DD): 02/01/2020');

it('computes money exactly from decimal string inputs', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-EXACT</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-EXACT</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>1000.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0010', '0.145');

    $xml = $doc->createBid($bid)->toString();

    expect($xml)->toContain('<UP>0.145</UP>')
        ->and($xml)->toContain('<IT>145.00</IT>');
});

it('rounds decimal-string prices half-up to three decimals', function () {
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-EXACT</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-EXACT</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>100.000</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0010', '12.3456');

    $xml = $doc->createBid($bid)->toString();

    expect($xml)->toContain('<UP>12.346</UP>')
        ->and($xml)->toContain('<IT>1234.60</IT>');
});

it('rejects non-numeric price strings', function () {
    makeBid()->setUnitPrice('0010', 'abc');
})->throws(GaebWriteException::class);

it('wraps a non-numeric source Qty in a GaebWriteException instead of crashing', function () {
    // Lenient reading coerces `n/a` to float 0.0 (passes parsing), so the
    // writer only discovers the garbage when it re-reads the raw string.
    $source = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3">
      <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
      <PrjInfo><NamePrj>PRJ-BADQTY</NamePrj><Cur>EUR</Cur></PrjInfo>
      <Award>
        <DP>83</DP>
        <AwardInfo><Cur>EUR</Cur></AwardInfo>
        <BoQ ID="B1">
          <BoQInfo>
            <Name>LV-BADQTY</Name>
            <BoQBkdn><Type>Item</Type><Length>4</Length><Num>Yes</Num></BoQBkdn>
          </BoQInfo>
          <BoQBody>
            <Itemlist>
              <Item ID="I1" RNoPart="0010">
                <Qty>n/a</Qty>
                <QU>m</QU>
              </Item>
            </Itemlist>
          </BoQBody>
        </BoQ>
      </Award>
    </GAEB>
    XML;
    $doc = GaebDocument::fromString($source);
    $bid = makeBid();
    $bid->setUnitPrice('0010', 1.0);

    $doc->createBid($bid);
})->throws(GaebWriteException::class, '0010');

it('throws when the Bid date is calendar-invalid', function () {
    new Bid(new Contractor(
        name: 'Muster Bau GmbH',
        street: 'Handwerkerweg 1',
        zip: '53179',
        city: 'Bonn',
        email: 'bau@example.test',
        phone: null,
    ), date: '2024-13-45');
})->throws(GaebWriteException::class, 'Invalid date (expected YYYY-MM-DD): 2024-13-45');
