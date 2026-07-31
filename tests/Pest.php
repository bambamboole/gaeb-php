<?php declare(strict_types=1);

use Brick\Math\BigDecimal;

/** Numeric equality against a ?BigDecimal, scale-insensitive (45.5 == "45.50"). */
expect()->extend('toBeDecimal', function (float|int|string $expected) {
    expect($this->value)->toBeInstanceOf(BigDecimal::class);
    expect($this->value->isEqualTo((string) $expected))->toBeTrue(
        "Expected decimal {$expected}, got ".var_export((string) $this->value, true),
    );

    return $this;
});

function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
}
