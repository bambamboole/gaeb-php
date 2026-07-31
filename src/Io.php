<?php declare(strict_types=1);

namespace Bambamboole\Gaeb;

/** @internal */
final class Io
{
    public static function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GaebParseException("Cannot read file: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return $content;
    }
}
