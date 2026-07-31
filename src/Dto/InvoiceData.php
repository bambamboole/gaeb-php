<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class InvoiceData
{
    /** @param list<Payment> $payments */
    public function __construct(
        public ?string $invoiceNo,
        public ?string $invoiceDate,
        public ?InvoiceType $type,
        public bool $creditNote,
        public ?SettlementType $settlementType,
        public ?int $sequentialNo,
        public ?string $servicePeriodStart,
        public ?string $servicePeriodEnd,
        public ?Party $creator,
        public ?Party $recipient,
        public array $payments,
        public ?float $totalGross,
    ) {}
}
