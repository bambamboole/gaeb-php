<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Driver\GaebXmlDriver;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Write\Bid;
use Bambamboole\GaebParser\Write\BidWriter;

final class GaebDocument
{
    private ?GaebFile $file = null;

    private function __construct(
        private readonly \DOMDocument $dom,
        private readonly ?string $original,
    ) {}

    public static function open(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new GaebParseException("Cannot read file: {$path}");
        }
        $content = file_get_contents($path);
        if ($content === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return self::fromString($content);
    }

    public static function fromString(string $content): self
    {
        $dom = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadXML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new GaebParseException('Invalid XML');
        }
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new self($dom, $content);
    }

    /**
     * @internal used by createBid for derived documents
     */
    public static function fromDom(\DOMDocument $dom): self
    {
        return new self($dom, null);
    }

    public function file(): GaebFile
    {
        return $this->file ??= (new GaebXmlDriver)->parse($this->toString());
    }

    public function phase(): ?int
    {
        return $this->file()->info->phase;
    }

    /** @return list<string> schema errors, [] = valid */
    public function validate(?string $xsdDir = null): array
    {
        $xsdDir ??= dirname(__DIR__).'/docs/gaeb/3.3/2021-05_Leistungsverzeichnis';
        $phase = $this->phase();
        if ($phase === null || $phase < 80 || $phase > 87) {
            return ['Cannot resolve schema for phase: '.var_export($phase, true)];
        }
        $xsd = "{$xsdDir}/GAEB_DA_XML_{$phase}_3.3_2021-05.xsd";
        if (! is_file($xsd)) {
            return ["Schema file not found: {$xsd}"];
        }

        $previous = libxml_use_internal_errors(true);
        $valid = $this->dom->schemaValidate($xsd);
        $errors = array_map(
            fn (\LibXMLError $e) => trim($e->message).' (line '.$e->line.')',
            libxml_get_errors(),
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $valid ? [] : $errors;
    }

    public function toString(): string
    {
        return $this->original ?? ($this->dom->saveXML() ?: '');
    }

    public function save(string $path): void
    {
        $result = @file_put_contents($path, $this->toString());
        if ($result === false) {
            throw new GaebWriteException("Cannot write file: {$path}");
        }
    }

    /** Transforms this X81/X83 tender into a new X84 bid document. */
    public function createBid(Bid $bid): self
    {
        return self::fromDom((new BidWriter)->write($this->dom, $this->file(), $bid));
    }
}
