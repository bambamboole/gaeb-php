<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class Item
{
    public function __construct(
        public string $rNo,
        public string $rNoPart,
        public ?float $qty,
        public ?string $unit,
        public ?string $shortText,
        public ?string $longText,
        public ?string $descriptionXml,
        public ?float $unitPrice,
        public ?float $totalPrice,
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
        public ?float $billedQty,
        public ?int $changeOrderNo = null,
        public ?ChangeOrderStatus $changeOrderStatus = null,
        public bool $notOffered = false,
        public bool $qtyToBeDetermined = false,
        public ?float $vat = null,
        public ?float $discountPercent = null,
        /** @var array<int, float> UPComp1–6 unit-price components, keyed 1–6 */
        public array $upComponents = [],
        /** xs:ID of the Item element — target of MarkupItem IDREF references */
        public ?string $id = null,
    ) {}
}
