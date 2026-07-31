<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class Totals
{
    public function __construct(
        public ?float $total,
        public ?float $discountPercent,
        public ?float $discountAmount,
        public ?float $totalAfterDiscount,
        public ?float $vat,
        public ?float $vatAmount,
        public ?float $totalNet,
        public ?float $totalGross,
        public ?float $totalLumpSum = null,
        /** @var list<VatPart> per-rate VAT breakdown for multi-rate documents */
        public array $vatParts = [],
        /** @var array<int, float> TotalNetUpComp/UpComp1–6, keyed 1–6 */
        public array $netUpComponents = [],
    ) {}
}
