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
    ) {}
}
