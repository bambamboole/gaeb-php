<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParseException;
use Dom\XMLDocument;

it('parses a string and exposes the parsed model lazily', function () {
    $doc = GaebDocument::fromString(fixture('boq.x83'));

    expect($doc->phase())->toBe(83)
        ->and($doc->file()->project->name)->toBe('PRJ-1')
        ->and($doc->file())->toBe($doc->file());
});

it('throws GaebParseException instead of leaking ValueError on empty input', function () {
    GaebDocument::fromString('');
})->throws(GaebParseException::class, 'Invalid XML');

it('round-trips to string byte-identically', function () {
    $content = file_get_contents(__DIR__.'/fixtures/boq.x83');

    expect(GaebDocument::fromString($content)->toString())->toBe($content);
});

it('validates a schema-valid document against the bundled xsds', function () {
    expect(GaebDocument::fromString(fixture('boq.x83'))->validate())->toBe([]);
});

it('reports schema errors for invalid documents', function () {
    $errors = GaebDocument::fromString(fixture('nonconforming.x83'))->validate();

    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('Name');
});

it('reports a missing xsd instead of throwing', function () {
    $errors = GaebDocument::fromString(fixture('boq.x83'))->validate('/nonexistent');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('/nonexistent');
});

it('casts to string the same as toString()', function () {
    $doc = GaebDocument::fromString(fixture('boq.x83'));

    expect((string) $doc)->toBe($doc->toString());
});

it('json-encodes to the same output as its parsed file', function () {
    $doc = GaebDocument::fromString(fixture('boq.x83'));

    $json = json_encode($doc);

    expect($json)->toBe(json_encode($doc->file()))
        ->and($json)->toContain('PRJ-1');
});

it('accepts an existing XMLDocument via fromDocument', function () {
    $doc = GaebDocument::fromDocument(XMLDocument::createFromString(fixture('boq.x83')));

    expect($doc->phase())->toBe(83)
        ->and($doc->validate())->toBe([]);
});

it('rejects a non-GAEB XMLDocument in fromDocument', function () {
    GaebDocument::fromDocument(XMLDocument::createFromString('<foo/>'));
})->throws(GaebParseException::class, 'Missing <GAEB> root element');
