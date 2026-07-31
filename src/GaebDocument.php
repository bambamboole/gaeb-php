<?php declare(strict_types=1);

namespace Bambamboole\Gaeb;

use Bambamboole\Gaeb\Driver\GaebXmlDriver;
use Bambamboole\Gaeb\Dto\GaebFile;
use Bambamboole\Gaeb\Write\Bid;
use Bambamboole\Gaeb\Write\BidWriter;
use Bambamboole\Gaeb\Write\Invoice;
use Bambamboole\Gaeb\Write\InvoiceWriter;
use Bambamboole\Gaeb\Xml\Dom;
use Dom\XMLDocument;

final class GaebDocument implements \JsonSerializable, \Stringable
{
    private ?GaebFile $file = null;

    private function __construct(
        private readonly XMLDocument $dom,
        private readonly ?string $original,
    ) {}

    public static function fromString(string $content): self
    {
        try {
            $dom = Dom::parse($content);
        } catch (\DOMException|\ValueError) {
            throw new GaebParseException('Invalid XML');
        }
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new self($dom, $content);
    }

    public static function fromDocument(XMLDocument $dom): self
    {
        $root = $dom->documentElement;
        if ($root === null || $root->localName !== 'GAEB') {
            throw new GaebParseException('Missing <GAEB> root element');
        }

        return new self($dom, null);
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
        $phase = $this->phase();
        if ($phase === null || $phase < 80 || $phase === 88 || $phase > 89) {
            return ['Cannot resolve schema for phase: '.var_export($phase, true)];
        }
        // Prefer the raw DP token — DPs aren't always numeric ("89B") and
        // each token has its own XSD.
        $dp = $this->file()->info->dp;
        $token = $dp !== null && preg_match('/^8\d[A-Z]{0,2}$/', $dp) === 1 ? $dp : (string) $phase;
        $family = $phase === 89 ? '2021-05_Rechnung' : '2021-05_Leistungsverzeichnis';
        $xsdDir ??= dirname(__DIR__).'/docs/gaeb/3.3/'.$family;
        $xsd = "{$xsdDir}/GAEB_DA_XML_{$token}_3.3_2021-05.xsd";
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

    /** Transforms this X86 contract into a new X89 invoice document. */
    public function createInvoice(Invoice $invoice): self
    {
        $phase = $this->phase();
        if ($phase !== 86) {
            $got = $phase === null ? 'none' : "X{$phase}";
            throw new GaebWriteException("createInvoice requires an X86 source, got {$got}");
        }

        return self::fromDom((new InvoiceWriter)->write($this->dom, $this->file(), $invoice));
    }

    /**
     * Transforms this X86 contract into a new X89B "Rechnungsbegründende
     * Unterlage" — the audit attachment accompanying an XRechnung/ZUGFeRD
     * e-invoice. Same strict billing rules as createInvoice(), but the
     * commercial data (recipient, shares, payments, TotalGross, invoice
     * header) is NOT part of the format — it lives in the e-invoice; the
     * header carries only RefInvoiceNo plus the service period.
     */
    public function createSupportingDocument(Invoice $invoice): self
    {
        $phase = $this->phase();
        if ($phase !== 86) {
            $got = $phase === null ? 'none' : "X{$phase}";
            throw new GaebWriteException("createSupportingDocument requires an X86 source, got {$got}");
        }

        $ns = 'http://www.gaeb.de/GAEB_DA_XML/DA89B/3.3';
        $x89 = (new InvoiceWriter)->write($this->dom, $this->file(), $invoice);
        $out = XMLDocument::createEmpty();
        $out->formatOutput = true;
        $root = $x89->documentElement;
        \assert($root !== null);
        $root = Dom::cloneInto($out, $root, $ns);
        $out->appendChild($root);

        $inv = Dom::child($root, 'Invoice');
        \assert($inv !== null);
        $dp = Dom::child($inv, 'DP');
        \assert($dp !== null);
        $dp->textContent = '89B';

        foreach (['InvoiceRecipient', 'InvoiceShare', 'PaymentMade', 'TotalGross'] as $name) {
            foreach (Dom::children($inv, $name) as $el) {
                $inv->removeChild($el);
            }
        }

        $header = $out->createElementNS($ns, 'InvoiceHeader');
        foreach ([
            'RefInvoiceNo' => $invoice->invoiceNo,
            'ServiceProvisionStartDate' => $invoice->servicePeriodStart,
            'ServiceProvisionEndDate' => $invoice->servicePeriodEnd,
        ] as $name => $text) {
            $el = $out->createElementNS($ns, $name);
            $el->textContent = $text;
            $header->appendChild($el);
        }
        $oldHeader = Dom::child($inv, 'InvoiceHeader');
        \assert($oldHeader !== null);
        $inv->replaceChild($header, $oldHeader);

        return self::fromDom($out);
    }

    /**
     * Re-stamps this X86 contract as a new X87 order confirmation (AN→AG):
     * same content under the DA87 namespace with DP 87 and a fresh GAEBInfo.
     */
    public function createOrderConfirmation(?string $date = null, string $progSystem = 'bambamboole/gaeb'): self
    {
        $phase = $this->phase();
        if ($phase !== 86) {
            $got = $phase === null ? 'none' : "X{$phase}";
            throw new GaebWriteException("createOrderConfirmation requires an X86 source, got {$got}");
        }
        if ($date !== null) {
            Assert::date($date);
        }
        $srcRoot = $this->dom->documentElement;
        $srcAward = $srcRoot !== null ? Dom::child($srcRoot, 'Award') : null;
        if ($srcRoot === null || $srcAward === null) {
            throw new GaebWriteException('Source document has no Award; cannot create an order confirmation.');
        }

        $ns = 'http://www.gaeb.de/GAEB_DA_XML/DA87/3.3';
        $out = XMLDocument::createEmpty();
        $out->formatOutput = true;
        $root = Dom::cloneInto($out, $srcRoot, $ns);
        $out->appendChild($root);

        $info = $out->createElementNS($ns, 'GAEBInfo');
        foreach (['Version' => '3.3', 'VersDate' => '2021-05', 'Date' => $date ?? date('Y-m-d'), 'ProgSystem' => $progSystem] as $name => $text) {
            $el = $out->createElementNS($ns, $name);
            $el->textContent = $text;
            $info->appendChild($el);
        }
        $oldInfo = Dom::child($root, 'GAEBInfo');
        $oldInfo === null ? $root->insertBefore($info, $root->firstChild) : $root->replaceChild($info, $oldInfo);

        $award = Dom::child($root, 'Award');
        \assert($award !== null);
        $dp = Dom::child($award, 'DP');
        if ($dp === null) {
            $dpEl = $out->createElementNS($ns, 'DP');
            $dpEl->textContent = '87';
            $award->insertBefore($dpEl, $award->firstChild);
        } else {
            $dp->textContent = '87';
        }

        return self::fromDom($out);
    }
}
