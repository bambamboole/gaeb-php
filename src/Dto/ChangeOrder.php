<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class ChangeOrder
{
    public function __construct(
        public ?int $no,
        public ?string $phase,
        public ?ChangeOrderStatus $status,
        public ?string $initiator,
        public ?string $reason,
        public ?string $reference,
        public ?string $date,
    ) {}
}
