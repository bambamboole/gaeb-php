<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class SubDescription
{
    public function __construct(
        public ?string $subDNo,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public ?float $qty,
        public ?string $unit,
        public ?float $unitPrice,
    ) {}
}
