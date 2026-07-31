<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class GaebInfo
{
    public function __construct(
        public ?string $version,
        public ?int $phase,
        public ?string $date,
        public ?string $program,
        /** Raw DP token — usually the phase digits, but e.g. "89B" for a Rechnungsbegründende Unterlage */
        public ?string $dp = null,
    ) {}
}
