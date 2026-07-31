<?php declare(strict_types=1);

use Bambamboole\Gaeb\Dto\Provisional;
use Bambamboole\Gaeb\Dto\TextComplementKind;
use Bambamboole\Gaeb\GaebParser;

it('returns null boq when the file has none', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->boq)->toBeNull();
});

it('parses the category tree', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;

    expect($boq->label)->toBe('Leistungsverzeichnis Testhalle')
        ->and($boq->currency)->toBe('EUR')
        ->and($boq->categories)->toHaveCount(2);

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
        ->and($first->qty)->toBeDecimal(100.0)
        ->and($first->unit)->toBe('m3')
        ->and($first->shortText)->toBe('Boden loesen')
        // longText flattens ALL <p> descendants of DetailTxt, including the
        // TextComplement caption/body/tail runs authored below — the driver
        // does not exclude complements from the plain-text long text.
        ->and($first->longText)->toBe("Boden loesen und lagern.\nBodenklasse 3-5.\nBodenklasse:\n3-5\n(gemaess Bodengutachten)")
        ->and($first->descriptionXml)->toContain('<DetailTxt>')
        ->and($first->unitPrice)->toBeNull()
        ->and($first->lumpSum)->toBeFalse();

    expect($second->rNo)->toBe('01.02.0020')
        ->and($second->qty)->toBeNull()
        ->and($second->unit)->toBe('psch')
        ->and($second->shortText)->toBe('Baustelle einrichten')
        ->and($second->longText)->toBeNull()
        ->and($second->lumpSum)->toBeTrue()
        ->and($second->textComplements)->toBe([])
        ->and($second->bidderComment)->toBeNull()
        ->and($second->subDescriptions)->toBe([]);
});

it('parses bid data on the owner-authored item: text complements and a sub-description', function () {
    $boq = GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq;
    $item = $boq->categories[0]->categories[0]->items[0];

    expect($item->rNo)->toBe('01.02.0010')
        ->and($item->textComplements)->toHaveCount(2);

    [$owner, $bidder] = $item->textComplements;
    expect($owner->markLabel)->toBe(1)
        ->and($owner->kind)->toBe(TextComplementKind::Owner)
        ->and($owner->caption)->toBe('Bodenklasse:')
        ->and($owner->body)->toBe('3-5')
        ->and($owner->tail)->toBe('(gemaess Bodengutachten)');

    // The bidder's gap: empty ComplBody (nothing to flatten), no caption/tail.
    expect($bidder->markLabel)->toBe(2)
        ->and($bidder->kind)->toBe(TextComplementKind::Bidder)
        ->and($bidder->caption)->toBeNull()
        ->and($bidder->body)->toBeNull()
        ->and($bidder->tail)->toBeNull();

    // X83's restricted Item type has no BidComm element at all (verified with
    // xmllint against GAEB_DA_XML_83_3.3_2021-05.xsd) — bidder comments only
    // exist on the X84 bid-submission side.
    expect($item->bidderComment)->toBeNull();

    expect($item->subDescriptions)->toHaveCount(1);
    $sub = $item->subDescriptions[0];
    expect($sub->subDNo)->toBe('1')
        ->and($sub->shortText)->toBe('Trockener Boden')
        ->and($sub->longText)->toBe('Trockener Boden je m3.')
        ->and($sub->descriptionXml)->toContain('Trockener Boden je m3.')
        ->and($sub->qty)->toBeDecimal(60.0)
        ->and($sub->unit)->toBe('m3')
        // X83's restricted tgSubDescr carries only UPSpec/UPBkdn (yes/no
        // flags), never a real UP element — that only exists in X84's
        // tgSubDescr (verified with xmllint), so unitPrice stays null here.
        ->and($sub->unitPrice)->toBeNull();
});

it('parses item classification flags', function () {
    $items = iterator_to_array(GaebParser::fromFile(__DIR__.'/fixtures/boq.x83')->boq->allItems(), false);
    $byRNo = [];
    foreach ($items as $item) {
        $byRNo[$item->rNo] = $item;
    }

    expect($byRNo['03.0030']->provisional)->toBe(Provisional::WithTotal)
        ->and($byRNo['03.0040']->alternativeGroupNo)->toBe(1)
        ->and($byRNo['03.0040']->alternativeSerialNo)->toBe(1)
        ->and($byRNo['03.0040.1']->alternativeGroupNo)->toBe(1)
        ->and($byRNo['03.0040.1']->alternativeSerialNo)->toBe(2)
        ->and($byRNo['03.0050']->hourlyWork)->toBeTrue()
        ->and($byRNo['01.02.0010']->provisional)->toBeNull()
        ->and($byRNo['01.02.0010']->hourlyWork)->toBeFalse()
        ->and($byRNo['01.02.0010']->notApplicable)->toBeFalse()
        ->and($byRNo['01.02.0010']->alternativeGroupNo)->toBeNull()
        ->and($byRNo['01.02.0010']->alternativeSerialNo)->toBeNull();
});

it('parses NotAppl and treats an unknown Provis value as null', function () {
    // X83's restricted Item type drops NotAppl entirely (verified with xmllint
    // against GAEB_DA_XML_83_3.3_2021-05.xsd), so it can't live in boq.x83
    // without breaking schema validity — exercised inline instead.
    $xml = <<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA82/3.3">
          <GAEBInfo><Version>3.3</Version><VersDate>2021-05</VersDate><Date>2024-01-15</Date></GAEBInfo>
          <PrjInfo><NamePrj>PRJ-1</NamePrj></PrjInfo>
          <Award>
            <DP>82</DP>
            <BoQ ID="B1">
              <BoQInfo><Name>LV</Name></BoQInfo>
              <BoQBody>
                <Itemlist>
                  <Item ID="I0010" RNoPart="0010">
                    <Provis>Nonsense</Provis>
                    <NotAppl>Yes</NotAppl>
                    <QU>Stk</QU>
                  </Item>
                </Itemlist>
              </BoQBody>
            </BoQ>
          </Award>
        </GAEB>
        XML;

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->provisional)->toBeNull()
        ->and($item->notApplicable)->toBeTrue();
});
