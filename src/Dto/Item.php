<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

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
    ) {}
}
