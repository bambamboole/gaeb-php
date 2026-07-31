<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Xml;

/**
 * @internal
 */
final class Dom
{
    public static function child(\DOMElement $el, string $name): ?\DOMElement
    {
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    public static function text(\DOMElement $el, string $name): ?string
    {
        $node = self::child($el, $name);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<\DOMElement>
     */
    public static function children(\DOMElement $el, string $name): array
    {
        $result = [];
        foreach ($el->childNodes as $node) {
            if ($node instanceof \DOMElement && $node->localName === $name) {
                $result[] = $node;
            }
        }

        return $result;
    }

    public static function floatVal(\DOMElement $el, string $name): ?float
    {
        $value = self::text($el, $name);

        return $value === null ? null : (float) $value;
    }

    public static function intVal(\DOMElement $el, string $name): ?int
    {
        $value = self::text($el, $name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Flatten GAEB rich text (<p><span>…) to plain text, paragraphs joined by newlines. */
    public static function flatten(?\DOMElement $el): ?string
    {
        if ($el === null) {
            return null;
        }
        $paragraphs = $el->getElementsByTagNameNS('*', 'p');
        if ($paragraphs->length === 0) {
            $value = trim($el->textContent);

            return $value === '' ? null : $value;
        }
        $lines = [];
        foreach ($paragraphs as $p) {
            if (self::hasAncestorP($p, $el)) {
                continue;
            }
            $line = trim($p->textContent);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /** Whether $node has a <p> ancestor strictly between it and $el (exclusive). */
    private static function hasAncestorP(\DOMElement $node, \DOMElement $el): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof \DOMElement && $parent !== $el) {
            if ($parent->localName === 'p') {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }
}
