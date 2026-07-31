<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class MarkupItem
{
    /** @param list<MarkupSubQuantity> $subQuantities */
    public function __construct(
        public string $rNo,
        public string $rNoPart,
        public ?string $id,
        public ?MarkupType $markupType,
        /** xs:IDREF pointing at the referenced Item's ID attribute */
        public ?string $refItemId,
        public array $subQuantities,
        public ?BigDecimal $markupPercent,
        public ?BigDecimal $markupTotal,
        public ?BigDecimal $totalPrice,
        public ?BigDecimal $discountPercent,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public bool $notApplicable,
        public bool $hourlyWork,
        public ?Provisional $provisional,
        public ?int $changeOrderNo = null,
        public ?ChangeOrderStatus $changeOrderStatus = null,
    ) {}
}
