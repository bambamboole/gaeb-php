<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class BoQCategory
{
    /**
     * @param  list<BoQCategory>  $categories
     * @param  list<Item>  $items
     * @param  list<MarkupItem>  $markupItems
     * @param  list<Remark>  $remarks
     * @param  list<PerformanceDescription>  $performanceDescriptions
     */
    public function __construct(
        public string $rNoPart,
        public ?string $label,
        public array $categories,
        public array $items,
        public ?int $changeOrderNo = null,
        public ?ChangeOrderStatus $changeOrderStatus = null,
        public ?Totals $totals = null,
        public bool $notApplicable = false,
        public array $markupItems = [],
        public array $remarks = [],
        public array $performanceDescriptions = [],
    ) {}
}
