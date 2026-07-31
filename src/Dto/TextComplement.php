<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class TextComplement
{
    public function __construct(
        public int $markLabel,
        public TextComplementKind $kind,
        public ?string $caption,
        public ?string $body,
        public ?string $tail,
    ) {}
}
