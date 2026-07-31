<?php declare(strict_types=1);

namespace Bambamboole\Gaeb\Write;

use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\GaebWriteException;
use Brick\Math\BigDecimal;
use Dom\Element;
use Dom\Text;
use Dom\XMLDocument;

/**
 * @internal shared DOM emission for the phase writers; each writer overrides
 * NS with its target phase namespace.
 */
abstract class Writer
{
    protected const string NS = '';

    /** @return ?array{Element, BigDecimal} */
    abstract protected function buildBoQCtgy(XMLDocument $out, Element $srcCtgy, string $prefix): ?array;

    /** @return array{list<Element>, BigDecimal} */
    abstract protected function buildItemlist(XMLDocument $out, Element $srcList, string $prefix): array;

    protected function buildGaebInfo(XMLDocument $out, ?string $date, string $progSystem): Element
    {
        $info = $out->createElementNS(static::NS, 'GAEBInfo');
        $info->appendChild($this->elem($out, 'Version', '3.3'));
        $info->appendChild($this->elem($out, 'VersDate', '2021-05'));
        $info->appendChild($this->elem($out, 'Date', $date ?? date('Y-m-d')));
        $info->appendChild($this->elem($out, 'ProgSystem', $progSystem));

        return $info;
    }

    protected function buildPrjInfo(XMLDocument $out, GaebFile $file): Element
    {
        $name = $file->project->name;
        if ($name === null) {
            throw new GaebWriteException('Source project has no name; cannot write PrjInfo/NamePrj.');
        }

        $prj = $out->createElementNS(static::NS, 'PrjInfo');
        $prj->appendChild($this->elem($out, 'NamePrj', $name));
        if ($file->project->label !== null) {
            $prj->appendChild($this->elem($out, 'LblPrj', $file->project->label));
        }

        return $prj;
    }

    /** @return array{?Element, BigDecimal} */
    protected function buildBoQBody(XMLDocument $out, Element $srcBody, string $prefix): array
    {
        $bodyEl = null;
        $total = BigDecimal::zero()->toScale(2);

        foreach ($srcBody->childNodes as $node) {
            if (! $node instanceof Element) {
                continue;
            }
            if ($node->localName === 'BoQCtgy') {
                $built = $this->buildBoQCtgy($out, $node, $prefix);
                if ($built === null) {
                    continue;
                }
                [$ctgyEl, $ctgyTotal] = $built;
                $bodyEl ??= $out->createElementNS(static::NS, 'BoQBody');
                $bodyEl->appendChild($ctgyEl);
                $total = $total->plus($ctgyTotal);
            } elseif ($node->localName === 'Itemlist') {
                [$itemEls, $listTotal] = $this->buildItemlist($out, $node, $prefix);
                if ($itemEls === []) {
                    continue;
                }
                $bodyEl ??= $out->createElementNS(static::NS, 'BoQBody');
                $listEl = $out->createElementNS(static::NS, 'Itemlist');
                foreach ($itemEls as $itemEl) {
                    $listEl->appendChild($itemEl);
                }
                $bodyEl->appendChild($listEl);
                $total = $total->plus($listTotal);
            }
        }

        return [$bodyEl, $total];
    }

    protected function totals(XMLDocument $out, BigDecimal $total): Element
    {
        $totalsEl = $out->createElementNS(static::NS, 'Totals');
        $totalsEl->appendChild($this->elem($out, 'Total', (string) $total));

        return $totalsEl;
    }

    /** @param array<string, mixed> $fields Throws $messagePrefix plus the names of fields that are null or ''. */
    protected function assertRequired(array $fields, string $messagePrefix): void
    {
        $missing = [];
        foreach ($fields as $field => $value) {
            if ($value === null || $value === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new GaebWriteException($messagePrefix.implode(', ', $missing));
        }
    }

    /** Creates a phase-namespaced element, optionally with text content — createElementNS has no 3-arg text shorthand in the native Dom API. */
    protected function elem(XMLDocument $out, string $name, ?string $text = null): Element
    {
        $el = $out->createElementNS(static::NS, $name);
        if ($text !== null) {
            $el->textContent = $text;
        }

        return $el;
    }

    /** Clones $el into the target document under the phase namespace, preserving structure and text. */
    protected function reNamespace(XMLDocument $out, Element $el): Element
    {
        $new = $out->createElementNS(static::NS, $el->localName);
        foreach ($el->attributes ?? [] as $attr) {
            $new->setAttribute($attr->name, $attr->value);
        }
        foreach ($el->childNodes as $child) {
            if ($child instanceof Element) {
                $new->appendChild($this->reNamespace($out, $child));
            } elseif ($child instanceof Text) {
                $new->appendChild($out->createTextNode($child->wholeText));
            }
        }

        return $new;
    }
}
