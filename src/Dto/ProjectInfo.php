<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class ProjectInfo
{
    public function __construct(
        public ?string $name,
        public ?string $label,
        public ?string $currency,
    ) {}
}
