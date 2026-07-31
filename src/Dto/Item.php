<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

use Brick\Math\BigDecimal;

final readonly class Item
{
    public function __construct(
        public string $rNo,
        public string $rNoPart,
        public ?BigDecimal $qty,
        public ?string $unit,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public ?BigDecimal $unitPrice,
        public ?BigDecimal $totalPrice,
        public bool $lumpSum,
        public ?Provisional $provisional,
        public bool $hourlyWork,
        public bool $notApplicable,
        public ?int $alternativeGroupNo,
        public ?int $alternativeSerialNo,
        /** @var list<TextComplement> */
        public array $textComplements,
        public ?string $bidderComment,
        /** @var list<SubDescription> */
        public array $subDescriptions,
        public ?BigDecimal $billedQty,
        public ?int $changeOrderNo = null,
        public ?ChangeOrderStatus $changeOrderStatus = null,
        public bool $notOffered = false,
        public bool $qtyToBeDetermined = false,
        public ?BigDecimal $vat = null,
        public ?BigDecimal $discountPercent = null,
        /** @var array<int, BigDecimal> UPComp1–6 unit-price components, keyed 1–6 */
        public array $upComponents = [],
        /** xs:ID of the Item element — target of MarkupItem IDREF references */
        public ?string $id = null,
    ) {}
}
