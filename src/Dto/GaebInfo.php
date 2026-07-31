<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class GaebInfo
{
    public function __construct(
        public ?string $version,
        public ?int $phase,
        public ?string $date,
        public ?string $program,
    ) {}
}
