<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class SubDescription
{
    public function __construct(
        public ?string $subDNo,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public ?BigDecimal $qty,
        public ?string $unit,
        public ?BigDecimal $unitPrice,
    ) {}
}
