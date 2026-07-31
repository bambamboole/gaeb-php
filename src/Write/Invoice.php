<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Write;

use Bambamboole\GaebParser\Assert;
use Bambamboole\GaebParser\Dto\InvoiceType;
use Bambamboole\GaebParser\Dto\Payment;
use Bambamboole\GaebParser\GaebWriteException;
use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;

final class Invoice
{
    /** @var array<string, BigDecimal> cumulative billed quantity by rNo */
    private array $quantities = [];

    /** @var list<Payment> */
    private array $payments = [];

    public function __construct(
        public readonly string $invoiceNo,
        public readonly string $invoiceDate,
        public readonly InvoiceType $type,
        public readonly string $servicePeriodStart,
        public readonly string $servicePeriodEnd,
        public readonly string $creatorTaxNo,
        public readonly ?string $vatPercent = null,
        public readonly ?string $date = null,
        public readonly ?int $sequentialNo = null,
        public readonly bool $creditNote = false,
    ) {
        if ($invoiceNo === '') {
            throw new GaebWriteException('invoiceNo must not be empty');
        }
        if ($creatorTaxNo === '') {
            throw new GaebWriteException('creatorTaxNo must not be empty');
        }
        Assert::date($invoiceDate);
        Assert::date($servicePeriodStart);
        Assert::date($servicePeriodEnd);
        if ($date !== null) {
            Assert::date($date);
        }
        if ($vatPercent !== null) {
            try {
                BigDecimal::of($vatPercent);
            } catch (MathException $e) {
                throw new GaebWriteException("Invalid vatPercent: {$e->getMessage()}", previous: $e);
            }
        }
    }

    public function billQty(string $rNo, BigNumber|string|float|int $qty): self
    {
        try {
            $this->quantities[$rNo] = BigDecimal::of(is_float($qty) ? (string) $qty : $qty);
        } catch (MathException $e) {
            throw new GaebWriteException("Invalid billed quantity for {$rNo}: {$e->getMessage()}", previous: $e);
        }

        return $this;
    }

    public function addPayment(Payment $payment): self
    {
        $this->payments[] = $payment;

        return $this;
    }

    /** @internal @return array<string, BigDecimal> */
    public function quantities(): array
    {
        return $this->quantities;
    }

    /** @internal @return list<Payment> */
    public function payments(): array
    {
        return $this->payments;
    }
}
