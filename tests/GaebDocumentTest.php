<?php declare(strict_types=1);

use Bambamboole\GaebParser\GaebDocument;
use Bambamboole\GaebParser\GaebParseException;
use Bambamboole\GaebParser\GaebWriteException;

it('opens a file and exposes the parsed model lazily', function () {
    $doc = GaebDocument::open(__DIR__.'/fixtures/boq.x83');

    expect($doc->phase())->toBe(83)
        ->and($doc->file()->project->name)->toBe('PRJ-1')
        ->and($doc->file())->toBe($doc->file());
});

it('throws on unreadable or invalid input', function () {
    GaebDocument::open(__DIR__.'/fixtures/nope.x83');
})->throws(GaebParseException::class);

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

it('saves to a file', function () {
    $target = sys_get_temp_dir().'/gaeb-doc-test.x83';
    if (is_file($target)) {
        unlink($target);
    }
    GaebDocument::open(__DIR__.'/fixtures/boq.x83')->save($target);

    expect(file_get_contents($target))->toBe(file_get_contents(__DIR__.'/fixtures/boq.x83'));
    if (is_file($target)) {
        unlink($target);
    }
});

it('throws GaebWriteException when saving to an unwritable path', function () {
    GaebDocument::open(__DIR__.'/fixtures/boq.x83')->save('/nonexistent-dir/x.x83');
})->throws(GaebWriteException::class);
