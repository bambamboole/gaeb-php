<?php declare(strict_types=1);

namespace Bambamboole\GaebParser;

use Bambamboole\GaebParser\Dto\GaebFile;
use Bambamboole\GaebParser\Dto\GaebInfo;
use Bambamboole\GaebParser\Dto\ProjectInfo;

final class GaebParser
{
    public static function fromFile(string $path): GaebFile
    {
        $xml = @file_get_contents($path);
        if ($xml === false) {
            throw new GaebParseException("Cannot read file: {$path}");
        }

        return self::fromString($xml);
    }

    public static function fromString(string $xml): GaebFile
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw new GaebParseException('Invalid XML');
        }

        $root = $doc->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new GaebFile(
            info: self::parseInfo($root),
            project: self::parseProject($root),
        );
    }

    private static function parseInfo(\DOMElement $root): GaebInfo
    {
        $info = self::child($root, 'GAEBInfo');
        $award = self::child($root, 'Award');

        $phase = null;
        if ($award !== null && ($dp = self::text($award, 'DP')) !== null) {
            $phase = (int) $dp;
        } elseif (preg_match('~/DA(8\d)/~', (string) $root->namespaceURI, $m) === 1) {
            $phase = (int) $m[1];
        }

        return new GaebInfo(
            version: $info !== null ? self::text($info, 'Version') : null,
            phase: $phase,
            date: $info !== null ? self::text($info, 'Date') : null,
            program: $info !== null ? self::text($info, 'ProgSystem') : null,
        );
    }

    private static function parseProject(\DOMElement $root): ProjectInfo
    {
        $prj = self::child($root, 'PrjInfo');

        return new ProjectInfo(
            name: $prj !== null ? self::text($prj, 'Name') : null,
            label: $prj !== null ? self::text($prj, 'LblPrj') : null,
            currency: $prj !== null ? self::text($prj, 'Cur') : null,
        );
    }

    private static function child(\DOMElement $el, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    private static function text(\DOMElement $el, string $name): ?string
    {
        $node = self::child($el, $name);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }
}
