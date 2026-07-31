<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Driver\GaebXmlDriver;
use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Write\Bid;
use Bambamboole\GaebParser\Write\BidWriter;
use Bambamboole\GaebParser\Xml\Dom;
use Dom\XMLDocument;

final class GaebDocument implements \JsonSerializable, \Stringable
{
    private ?GaebFile $file = null;

    private function __construct(
        private readonly XMLDocument $dom,
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
        try {
            $dom = Dom::parse($content);
        } catch (\DOMException) {
            throw new GaebParseException('Invalid XML');
        }
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new self($dom, $content);
    }

    /** used by createBid for derived documents */
    private static function fromDom(XMLDocument $dom): self
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

        // schemaValidate only recognizes the validation root on a document
        // that came through libxml's parser; a document built purely via
        // the construction API (createEmpty + createElementNS, as
        // BidWriter does) fails with "No matching global declaration"
        // even when well-formed and schema-conformant. Re-parsing the
        // serialized form guarantees a parsed document either way.
        $previous = libxml_use_internal_errors(true);
        $valid = Dom::parse($this->toString())->schemaValidate($xsd);
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
        return $this->original ?? ($this->dom->saveXml() ?: '');
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    public function jsonSerialize(): GaebFile
    {
        return $this->file();
    }

    /** Transforms this X81/X83 tender into a new X84 bid document. */
    public function createBid(Bid $bid): self
    {
        $phase = $this->phase();
        if ($phase === null || $phase < 81 || $phase > 83) {
            $got = $phase === null ? 'none' : "X{$phase}";
            throw new GaebWriteException("createBid requires an X81–X83 source, got {$got}");
        }

        return self::fromDom((new BidWriter)->write($this->dom, $this->file(), $bid));
    }
}
