<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebParseException;
use Bambamboole\Gaeb\GaebParser;

it('throws on invalid xml', function () {
    GaebParser::fromString('not xml at all');
})->throws(GaebParseException::class);

it('throws on xml without GAEB root', function () {
    GaebParser::fromString('<?xml version="1.0"?><Other/>');
})->throws(GaebParseException::class);

it('throws on unreadable file', function () {
    GaebParser::fromFile(__DIR__.'/fixtures/does-not-exist.x83');
})->throws(GaebParseException::class);

it('parses file and project metadata', function () {
    $gaeb = GaebParser::fromFile(__DIR__.'/fixtures/minimal.x83');

    expect($gaeb->info->version)->toBe('3.3')
        ->and($gaeb->info->phase)->toBe(83)
        ->and($gaeb->info->date)->toBe('2024-01-15')
        ->and($gaeb->info->program)->toBe('TestAVA 1.0')
        ->and($gaeb->project->name)->toBe('PRJ-1')
        ->and($gaeb->project->label)->toBe('Neubau Testhalle')
        ->and($gaeb->project->currency)->toBe('EUR');
});

it('detects phase from namespace when DP element is missing', function () {
    $gaeb = GaebParser::fromString(
        '<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA84/3.3"><Award/></GAEB>'
    );

    expect($gaeb->info->phase)->toBe(84);
});

it('falls back to the namespace when DP is not numeric', function () {
    $gaeb = GaebParser::fromString(
        '<GAEB xmlns="http://www.gaeb.de/GAEB_DA_XML/DA84/3.3"><Award><DP>abc</DP></Award></GAEB>'
    );

    expect($gaeb->info->phase)->toBe(84);
});

it('parses leniently when optional metadata is missing', function () {
    $gaeb = GaebParser::fromString('<GAEB><Award/></GAEB>');

    expect($gaeb->info->version)->toBeNull()
        ->and($gaeb->info->phase)->toBeNull()
        ->and($gaeb->project->name)->toBeNull();
});
