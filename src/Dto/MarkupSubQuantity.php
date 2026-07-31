<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class MarkupSubQuantity
{
    public function __construct(
        /** xs:IDREF pointing at the referenced Item's ID attribute */
        public ?string $refItemId,
        public ?float $qty,
    ) {}
}
