<?php declare(strict_types=1);

namespace Bambamboole\GaebParser\Xml;

use Dom\Element;
use Dom\XMLDocument;

/**
 * @internal
 */
final class Dom
{
    /**
     * Parses XML into a Dom\XMLDocument, throwing \DOMException on malformed
     * input (same contract as Dom\XMLDocument::createFromString) but without
     * the accompanying E_WARNING — createFromString emits one on invalid XML
     * in addition to throwing, which callers must not let leak as a PHP
     * warning.
     */
    public static function parse(string $content): XMLDocument
    {
        set_error_handler(static fn (): bool => true);
        try {
            return XMLDocument::createFromString($content);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * Element::getAttribute() returns null for a missing attribute
     * (spec-compliant), unlike legacy DOMElement which returned ''. Callers
     * throughout this codebase rely on the legacy '' contract (e.g. `!==
     * ''` presence checks), so normalize here instead of at every call site.
     */
    public static function attr(Element $el, string $name): string
    {
        return $el->getAttribute($name) ?? '';
    }

    public static function child(Element $el, string $name): ?Element
    {
        foreach ($el->childNodes as $node) {
            if ($node instanceof Element && $node->localName === $name) {
                return $node;
            }
        }

        return null;
    }

    public static function text(Element $el, string $name): ?string
    {
        $node = self::child($el, $name);
        if ($node === null) {
            return null;
        }
        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<Element>
     */
    public static function children(Element $el, string $name): array
    {
        $result = [];
        foreach ($el->childNodes as $node) {
            if ($node instanceof Element && $node->localName === $name) {
                $result[] = $node;
            }
        }

        return $result;
    }

    public static function floatVal(Element $el, string $name): ?float
    {
        $value = self::text($el, $name);

        return $value === null ? null : (float) $value;
    }

    public static function intVal(Element $el, string $name): ?int
    {
        $value = self::text($el, $name);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    /** Flatten GAEB rich text (<p><span>…) to plain text, paragraphs joined by newlines. */
    public static function flatten(?Element $el): ?string
    {
        if ($el === null) {
            return null;
        }
        $paragraphs = $el->querySelectorAll('p');
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
    private static function hasAncestorP(Element $node, Element $el): bool
    {
        $parent = $node->parentNode;
        while ($parent instanceof Element && $parent !== $el) {
            if ($parent->localName === 'p') {
                return true;
            }
            $parent = $parent->parentNode;
        }

        return false;
    }
}
