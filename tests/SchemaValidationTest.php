<?php declare(strict_types=1);
use Dom\XMLDocument;

$xsdBase = getenv('GAEB_XSD_DIR') ?: __DIR__.'/../docs/gaeb/3.3';

$fixtures = [
    'description.x80' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_80_3.3_2021-05.xsd',
    'minimal.x83' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_83_3.3_2021-05.xsd',
    'boq.x83' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_83_3.3_2021-05.xsd',
    'markup.x83' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_83_3.3_2021-05.xsd',
    'components.x84' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_84_3.3_2021-05.xsd',
    'priced.x84' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_84_3.3_2021-05.xsd',
    'realistic.x84' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_84_3.3_2021-05.xsd',
    'contract.x86' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_86_3.3_2021-05.xsd',
    'nachtrag.x86' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_86_3.3_2021-05.xsd',
    'confirmation.x87' => '2021-05_Leistungsverzeichnis/GAEB_DA_XML_87_3.3_2021-05.xsd',
    'invoice.x89' => '2021-05_Rechnung/GAEB_DA_XML_89_3.3_2021-05.xsd',
];

foreach ($fixtures as $fixture => $xsd) {
    it("validates {$fixture} against the official schema", function () use ($xsdBase, $fixture, $xsd) {
        if (! is_dir($xsdBase)) {
            $this->markTestSkipped('GAEB XSDs not available (set GAEB_XSD_DIR or place them in docs/gaeb/)');
        }

        $doc = XMLDocument::createFromFile(__DIR__.'/fixtures/'.$fixture);

        $previous = libxml_use_internal_errors(true);
        $valid = $doc->schemaValidate($xsdBase.'/'.$xsd);
        $errors = array_map(
            fn (LibXMLError $e) => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        expect($valid)->toBeTrue('Schema errors: '.implode('; ', $errors));
    });
}
