<?php declare(strict_types=1);

use Bambamboole\GaebParser\Dto\TextComplementKind;
use Bambamboole\GaebParser\GaebParser;

$bidItemXml = function (string $itemChildren): string {
    return '<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3"><Award><DP>83</DP><BoQ><BoQBody><Itemlist>'
        .'<Item RNoPart="0010">'.$itemChildren.'</Item>'
        .'</Itemlist></BoQBody></BoQ></Award></GAEB>';
};

it('parses text complements with caption, body and tail', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <Description><CompleteText><DetailTxt>
            <Text><p><span>Rohrleitung DN </span></p></Text>
            <TextComplement MarkLbl="1" Kind="Owner">
                <ComplCaption><p><span>Nennweite</span></p></ComplCaption>
                <ComplBody><p><span>100</span></p></ComplBody>
                <ComplTail><p><span>mm</span></p></ComplTail>
            </TextComplement>
            <TextComplement MarkLbl="2" Kind="Bidder">
                <ComplBody><p><span>Fabrikat Musterrohr</span></p></ComplBody>
            </TextComplement>
        </DetailTxt></CompleteText></Description>
        XML);

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->textComplements)->toHaveCount(2);
    [$owner, $bidder] = $item->textComplements;
    expect($owner->markLabel)->toBe(1)
        ->and($owner->kind)->toBe(TextComplementKind::Owner)
        ->and($owner->caption)->toBe('Nennweite')
        ->and($owner->body)->toBe('100')
        ->and($owner->tail)->toBe('mm')
        ->and($bidder->markLabel)->toBe(2)
        ->and($bidder->kind)->toBe(TextComplementKind::Bidder)
        ->and($bidder->caption)->toBeNull()
        ->and($bidder->body)->toBe('Fabrikat Musterrohr');
});

it('skips complements with unknown kind and defaults missing MarkLbl to 0', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <Description><CompleteText><DetailTxt>
            <TextComplement MarkLbl="7" Kind="Alien"><ComplBody><p><span>x</span></p></ComplBody></TextComplement>
            <TextComplement Kind="Bidder"><ComplBody><p><span>y</span></p></ComplBody></TextComplement>
        </DetailTxt></CompleteText></Description>
        XML);

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->textComplements)->toHaveCount(1)
        ->and($item->textComplements[0]->markLabel)->toBe(0)
        ->and($item->textComplements[0]->body)->toBe('y');
});

it('joins multiple bidder comments with newlines', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <BidComm><p><span>Lieferzeit 6 Wochen</span></p></BidComm>
        <BidComm><p><span>Nur Fabrikat X</span></p></BidComm>
        XML);

    expect(GaebParser::fromString($xml)->boq->items[0]->bidderComment)
        ->toBe("Lieferzeit 6 Wochen\nNur Fabrikat X");
});

it('parses sub-descriptions', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <SubDescr>
            <SubDNo>1</SubDNo>
            <Description><CompleteText>
                <DetailTxt><Text><p><span>Zulage Bogen</span></p></Text></DetailTxt>
                <OutlineText><OutlTxt><TextOutlTxt><p><span>Bogen</span></p></TextOutlTxt></OutlTxt></OutlineText>
            </CompleteText></Description>
            <Qty>4.000</Qty>
            <QU>St</QU>
            <UP>12.50</UP>
        </SubDescr>
        XML);

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->subDescriptions)->toHaveCount(1);
    $sub = $item->subDescriptions[0];
    expect($sub->subDNo)->toBe('1')
        ->and($sub->shortText)->toBe('Bogen')
        ->and($sub->longText)->toBe('Zulage Bogen')
        ->and($sub->descriptionXml)->toContain('Zulage Bogen')
        ->and($sub->qty)->toBe(4.0)
        ->and($sub->unit)->toBe('St')
        ->and($sub->unitPrice)->toBe(12.50);
});

it('defaults bid fields to empty on plain items', function () use ($bidItemXml) {
    $item = GaebParser::fromString($bidItemXml('<Qty>1.000</Qty>'))->boq->items[0];

    expect($item->textComplements)->toBe([])
        ->and($item->bidderComment)->toBeNull()
        ->and($item->subDescriptions)->toBe([]);
});

it('does not duplicate text nested inside a TextComplement/ComplBody <p> inside the outer <p>', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <Description><CompleteText><DetailTxt>
            <Text><p><span>Rohr DN </span><TextComplement MarkLbl="1" Kind="Owner"><ComplBody><p><span>100</span></p></ComplBody></TextComplement><span> verlegen.</span></p></Text>
        </DetailTxt></CompleteText></Description>
        XML);

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->longText)->toBe('Rohr DN 100 verlegen.')
        ->and($item->textComplements[0]->body)->toBe('100');
});

it('parses a text complement placed in the OutlineText/OutlTxt choice, outside DetailTxt', function () use ($bidItemXml) {
    $xml = $bidItemXml(<<<'XML'
        <Description><CompleteText>
            <OutlineText><OutlTxt><TextOutlTxt><p><span>Kurztext</span></p></TextOutlTxt><TextComplement MarkLbl="3" Kind="Bidder"><ComplBody><p><span>gap</span></p></ComplBody></TextComplement></OutlTxt></OutlineText>
        </CompleteText></Description>
        XML);

    $item = GaebParser::fromString($xml)->boq->items[0];

    expect($item->shortText)->toBe('Kurztext')
        ->and($item->textComplements)->toHaveCount(1)
        ->and($item->textComplements[0]->markLabel)->toBe(3)
        ->and($item->textComplements[0]->body)->toBe('gap');
});
