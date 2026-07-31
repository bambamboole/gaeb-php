<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Dto;

final readonly class BoQ
{
    /**
     * @param  list<BoQCategory>  $categories
     * @param  list<Item>  $items
     */
    public function __construct(
        public ?string $label,
        public ?string $currency,
        public ?float $total,
        public array $categories,
        public array $items,
    ) {}

    /**
     * Iterates depth-first: a level's direct items are yielded before
     * descending into its categories — not necessarily document order.
     *
     * @return \Generator<int, Item>
     */
    public function allItems(): \Generator
    {
        yield from $this->items;
        yield from self::walk($this->categories);
    }

    /**
     * @param  list<BoQCategory>  $categories
     * @return \Generator<int, Item>
     */
    private static function walk(array $categories): \Generator
    {
        foreach ($categories as $category) {
            yield from $category->items;
            yield from self::walk($category->categories);
        }
    }
}
