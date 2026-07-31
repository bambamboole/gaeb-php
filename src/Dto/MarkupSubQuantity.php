<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class MarkupSubQuantity
{
    public function __construct(
        /** xs:IDREF pointing at the referenced Item's ID attribute */
        public ?string $refItemId,
        public ?BigDecimal $qty,
    ) {}
}
