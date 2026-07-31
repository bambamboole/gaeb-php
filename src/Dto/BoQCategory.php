<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Dto;

final readonly class BoQCategory
{
    /**
     * @param  list<BoQCategory>  $categories
     * @param  list<Item>  $items
     */
    public function __construct(
        public string $rNoPart,
        public ?string $label,
        public array $categories,
        public array $items,
    ) {}
}
