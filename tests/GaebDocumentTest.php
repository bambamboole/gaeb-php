<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebDocument;
use Bambamboole\GaebParser\GaebParseException;

it('opens a file and exposes the parsed model lazily', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');

    expect($doc->phase())->toBe(83)
        ->and($doc->file()->project->name)->toBe('PRJ-1')
        ->and($doc->file())->toBe($doc->file());
});

it('throws on unreadable or invalid input', function () {
    GaebDocument::open(__DIR__.'/fixtures/nope.x83');
})->throws(GaebParseException::class);

it('throws GaebParseException instead of leaking ValueError on empty input', function () {
    GaebDocument::fromString('');
})->throws(GaebParseException::class, 'Invalid XML');

it('round-trips to string byte-identically', function () {
    $content = file_get_contents(__DIR__.'/fixtures/boq.x83');

    expect(GaebDocument::fromString($content)->toString())->toBe($content);
});

it('validates a schema-valid document against the bundled xsds', function () {
    expect(GaebDocument::open(__DIR__.'/fixtures/boq.x83')->validate())->toBe([]);
});

it('reports schema errors for invalid documents', function () {
    $errors = GaebDocument::open(__DIR__.'/fixtures/nonconforming.x83')->validate();

    expect($errors)->not->toBe([])
        ->and(implode(' ', $errors))->toContain('Name');
});

it('reports a missing xsd instead of throwing', function () {
    $errors = GaebDocument::open(__DIR__.'/fixtures/boq.x83')->validate('/nonexistent');

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('/nonexistent');
});

it('casts to string the same as toString()', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');

    expect((string) $doc)->toBe($doc->toString());
});

it('json-encodes to the same output as its parsed file', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');

    $json = json_encode($doc);

    expect($json)->toBe(json_encode($doc->file()))
        ->and($json)->toContain('PRJ-1');
});
