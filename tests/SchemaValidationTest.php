<?php declare(strict_types=1);

$xsdDir = getenv('GAEB_XSD_DIR') ?: __DIR__.'/../docs/gaeb/3.3/2021-05_Leistungsverzeichnis';

$fixtures = [
    'minimal.x83' => 'GAEB_DA_XML_83_3.3_2021-05.xsd',
    'boq.x83' => 'GAEB_DA_XML_83_3.3_2021-05.xsd',
    'priced.x84' => 'GAEB_DA_XML_84_3.3_2021-05.xsd',
    'realistic.x84' => 'GAEB_DA_XML_84_3.3_2021-05.xsd',
];

foreach ($fixtures as $fixture => $xsd) {
    it("validates {$fixture} against the official schema", function () use ($xsdDir, $fixture, $xsd) {
        if (! is_dir($xsdDir)) {
            $this->markTestSkipped('GAEB XSDs not available (set GAEB_XSD_DIR or place them in docs/gaeb/)');
        }

        $doc = new DOMDocument;
        $doc->load(__DIR__.'/fixtures/'.$fixture);

        $previous = libxml_use_internal_errors(true);
        $valid = $doc->schemaValidate($xsdDir.'/'.$xsd);
        $errors = array_map(
            fn (LibXMLError $e) => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        expect($valid)->toBeTrue('Schema errors: '.implode('; ', $errors));
    });
}
