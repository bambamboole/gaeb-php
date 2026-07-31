<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class VatPart
{
    public function __construct(
        public ?BigDecimal $percent,
        public ?BigDecimal $totalNetPart,
        public ?BigDecimal $vatAmount,
    ) {}
}
