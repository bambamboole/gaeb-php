<?php declare(strict_types=1);

namespace Bambamboole\Gaeb;

use Bambamboole\Gaeb\Driver\Driver;
use Bambamboole\Gaeb\Driver\GaebXmlDriver;
use Bambamboole\Gaeb\Dto\GaebFile;

final class GaebParser
{
    /** @param list<Driver> $drivers */
    public function __construct(private readonly array $drivers = [new GaebXmlDriver]) {}

    public static function fromFile(string $path): GaebFile
    {
        return (new self)->parseFile($path);
    }

    public static function fromString(string $content): GaebFile
    {
        return (new self)->parse($content);
    }

    public function parseFile(string $path): GaebFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return $this->parse($content);
    }

    public function parse(string $content): GaebFile
    {
        foreach ($this->drivers as $driver) {
            if ($driver->supports($content)) {
                return $driver->parse($content);
            }
        }

        throw $this->unrecognized($content);
    }

    private function unrecognized(string $content): GaebParseException
    {
        $head = ltrim(substr($content, 0, 200), "\xEF\xBB\xBF \t\r\n");
        if (str_contains($head, '#begin[')) {
            return new GaebParseException('GAEB 2000 format detected — only GAEB DA XML is currently supported');
        }
        if (preg_match('/^\d{2}/', $head) === 1) {
            return new GaebParseException('GAEB 90 format detected — only GAEB DA XML is currently supported');
        }

        return new GaebParseException('Unrecognized file format');
    }
}
