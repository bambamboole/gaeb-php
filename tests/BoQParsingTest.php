<?php declare(strict_types=1);

use Bambamboole\GaebParser\Dto\Provisional;
use Bambamboole\GaebParser\GaebParser;

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
