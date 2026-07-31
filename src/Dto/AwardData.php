<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class AwardData
{
    public function __construct(
        public ?string $contractNo,
        public ?string $contractDate,
        public ?string $bidDate,
        public ?string $constructionStart,
        public ?string $constructionEnd,
        public ?int $warrantyDuration,
        public ?WarrantyUnit $warrantyUnit,
        public ?string $warrantyEnd,
    ) {}
}
