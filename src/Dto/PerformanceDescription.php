<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class PerformanceDescription
{
    public function __construct(
        public ?string $perfNo,
        public ?string $label,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
    ) {}
}
