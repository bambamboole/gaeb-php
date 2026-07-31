<?php declare(strict_types=1);

use Bambamboole\GaebParser\Driver\Driver;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\ProjectInfo;
use Bambamboole\GaebParser\GaebParseException;
use Bambamboole\GaebParser\GaebParser;

it('parses via the instance api', function () {
    $content = file_get_contents(__DIR__.'/fixtures/boq.x83');
    $gaeb = (new GaebParser)->parse($content);

    expect($gaeb->boq)->not->toBeNull();
});

it('parses a file via the instance api', function () {
    $gaeb = (new GaebParser)->parseFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->info->phase)->toBe(83);
});

it('uses the first supporting custom driver', function () {
    $canned = new GaebFile(new GaebInfo('9.9', null, null, null), new ProjectInfo(null, null, null), null);
    $driver = new class($canned) implements Driver
    {
        public function __construct(private readonly GaebFile $result) {}

        public function supports(string $content): bool
        {
            return true;
        }

        public function parse(string $content): GaebFile
        {
            return $this->result;
        }
    };

    expect((new GaebParser([$driver]))->parse('anything'))->toBe($canned);
});

it('rejects gaeb 2000 content with a clear error', function () {
    (new GaebParser)->parse("#begin[GAEB]\n#end[GAEB]");
})->throws(GaebParseException::class, 'GAEB 2000');

it('rejects gaeb 90 content with a clear error', function () {
    (new GaebParser)->parse("00K\n01Projekt XY\n");
})->throws(GaebParseException::class, 'GAEB 90');

it('parses UTF-16 BOM content', function () {
    $utf16 = "\xFF\xFE".mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA83/3.3"><Award><DP>83</DP></Award></GAEB>', 'UTF-16LE', 'UTF-8');

    expect((new GaebParser)->parse($utf16)->info->phase)->toBe(83);
});

it('rejects unrecognized content', function () {
    (new GaebParser)->parse('certainly not gaeb');
})->throws(GaebParseException::class, 'Unrecognized file format');
