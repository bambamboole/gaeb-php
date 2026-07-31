<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Write;

use Bambamboole\GaebParser\Dto\Contractor;
use Bambamboole\GaebParser\GaebWriteException;

final class Bid
{
    /** @var array<string, float> */
    private array $prices = [];

    /** @var array<string, array<int, string>> rNo => markLabel => text */
    private array $gapFills = [];

    /** @var array<string, string> */
    private array $comments = [];

    public function __construct(
        public readonly Contractor $contractor,
        public readonly ?string $currency = null,
        public readonly ?string $date = null,
    ) {
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new GaebWriteException("Invalid Bid date '{$date}'; expected YYYY-MM-DD.");
        }
    }

    public function setUnitPrice(string $rNo, float $unitPrice): self
    {
        $this->prices[$rNo] = $unitPrice;

        return $this;
    }

    public function fillGap(string $rNo, int $markLabel, string $text): self
    {
        $this->gapFills[$rNo][$markLabel] = $text;

        return $this;
    }

    public function setComment(string $rNo, string $comment): self
    {
        $this->comments[$rNo] = $comment;

        return $this;
    }

    /** @internal @return array<string, float> */
    public function prices(): array
    {
        return $this->prices;
    }

    /** @internal @return array<string, array<int, string>> */
    public function gapFills(): array
    {
        return $this->gapFills;
    }

    /** @internal @return array<string, string> */
    public function comments(): array
    {
        return $this->comments;
    }
}
