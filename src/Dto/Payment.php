<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class Payment
{
    public function __construct(
        public ?string $total,
        public ?string $totalVat,
        public ?string $discountAmount,
        public ?string $paymentDate,
        public ?string $invoiceNo,
        public ?string $paymentNo = null,
        public ?string $paymentNote = null,
    ) {}
}
