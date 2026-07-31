<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class Payment
{
    public function __construct(
        public ?BigDecimal $total,
        public ?BigDecimal $totalVat,
        public ?BigDecimal $discountAmount,
        public ?string $paymentDate,
        public ?string $invoiceNo,
        public ?string $paymentNo = null,
        public ?string $paymentNote = null,
    ) {}
}
