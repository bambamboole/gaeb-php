<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

/** @internal */
final class Assert
{
    public static function date(string $date): void
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) !== 1
            || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new GaebWriteException("Invalid date (expected YYYY-MM-DD): {$date}");
        }
    }
}
