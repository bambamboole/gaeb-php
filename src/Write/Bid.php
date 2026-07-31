<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Write;

use Bambamboole\Gaeb\Assert;
use Bambamboole\Gaeb\Dto\Party;
use Bambamboole\Gaeb\GaebWriteException;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;

final class Bid
{
    /** @var array<string, BigDecimal> */
    private array $prices = [];

    /** @var array<string, array<int, string>> rNo => markLabel => text */
    private array $gapFills = [];

    /** @var array<string, string> */
    private array $comments = [];

    public function __construct(
        public readonly Party $contractor,
        public readonly ?string $currency = null,
        public readonly ?string $date = null,
        public readonly string $progSystem = 'bambamboole/gaeb',
    ) {
        if ($date !== null) {
            Assert::date($date);
        }
    }

    public function setUnitPrice(string $rNo, BigNumber|string|float|int $unitPrice): self
    {
        // brick/math's BigDecimal::of() doesn't accept float directly (by
        // design — floats are lossy); string/int/BigNumber are the exact
        // path, float is a convenience cast through PHP's own (string) cast
        // (ini precision, default 14 digits, NOT round-trippable) — enough
        // for money magnitudes, but pass a decimal string for exactness.
        try {
            $this->prices[$rNo] = BigDecimal::of(is_float($unitPrice) ? (string) $unitPrice : $unitPrice);
        } catch (MathException $e) {
            throw new GaebWriteException("Invalid unit price for {$rNo}: {$e->getMessage()}", previous: $e);
        }

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

    /** @internal @return array<string, BigDecimal> */
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
