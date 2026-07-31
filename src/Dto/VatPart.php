<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class VatPart
{
    public function __construct(
        public ?float $percent,
        public ?float $totalNetPart,
        public ?float $vatAmount,
    ) {}
}
