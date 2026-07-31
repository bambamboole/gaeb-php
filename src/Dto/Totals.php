<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class Totals
{
    public function __construct(
        public ?BigDecimal $total,
        public ?BigDecimal $discountPercent,
        public ?BigDecimal $discountAmount,
        public ?BigDecimal $totalAfterDiscount,
        public ?BigDecimal $vat,
        public ?BigDecimal $vatAmount,
        public ?BigDecimal $totalNet,
        public ?BigDecimal $totalGross,
        public ?BigDecimal $totalLumpSum = null,
        /** @var list<VatPart> per-rate VAT breakdown for multi-rate documents */
        public array $vatParts = [],
        /** @var array<int, BigDecimal> TotalNetUpComp/UpComp1–6, keyed 1–6 */
        public array $netUpComponents = [],
    ) {}
}
