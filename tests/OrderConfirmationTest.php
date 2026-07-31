<?php declare(strict_types=1);

use Bambamboole\Gaeb\GaebDocument;
use Bambamboole\Gaeb\GaebParser;
use Bambamboole\Gaeb\GaebWriteException;

it('re-stamps an X86 contract as a schema-valid X87 order confirmation', function () {
    $contract = GaebDocument::fromString(fixture('contract.x86'));

    $x87 = $contract->createOrderConfirmation(date: '2026-06-20', progSystem: 'my-erp 1.0');

    expect($x87->phase())->toBe(87)
        ->and($x87->validate())->toBe([])
        ->and($x87->toString())->toContain('http://www.gaeb.de/GAEB_DA_XML/DA87/3.3')
        ->and($x87->toString())->toContain('<DP>87</DP>')
        ->and($x87->toString())->toContain('<Date>2026-06-20</Date>')
        ->and($x87->toString())->toContain('<ProgSystem>my-erp 1.0</ProgSystem>')
        ->and($contract->phase())->toBe(86);
});

it('preserves the contract content in the confirmation', function () {
    $contract = GaebDocument::fromString(fixture('contract.x86'));

    $gaeb = GaebParser::fromString($contract->createOrderConfirmation()->toString());

    expect($gaeb->owner->name)->toBe('Stadtwerke Musterstadt')
        ->and($gaeb->contractor->name)->toBe('Musterbau GmbH')
        ->and($gaeb->award->contractNo)->toBe('A-2026-042')
        ->and($gaeb->boq->totals->total)->toBeDecimal(3475.00)
        ->and(iterator_to_array($gaeb->boq->allItems(), false))->toHaveCount(2);
});

it('throws when the source phase is not X86', function () {
    GaebDocument::fromString(fixture('boq.x83'))->createOrderConfirmation();
})->throws(GaebWriteException::class, 'createOrderConfirmation requires an X86 source, got X83');

it('rejects an invalid confirmation date', function () {
    GaebDocument::fromString(fixture('contract.x86'))->createOrderConfirmation(date: '20.06.2026');
})->throws(GaebWriteException::class, 'Invalid date');
