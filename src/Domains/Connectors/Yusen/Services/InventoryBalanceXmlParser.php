<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Services;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Yusen\DataTransferObject\InventoryBalance;
use Kanvas\Connectors\Yusen\DataTransferObject\InventoryBalanceLine;
use Kanvas\Exceptions\ValidationException;
use SimpleXMLElement;
use Throwable;
use XMLReader;

/**
 * Parses Yusen's Manhattan ILSNET `Item Balance` document into per-item balances.
 *
 * Streams with XMLReader and materialises one `<Inventory>` element at a time: a full catalog
 * dump with serial numbers is tens of MB, and `simplexml_load_file` on the whole document would
 * hold the entire tree plus its object graph in the worker at once.
 */
class InventoryBalanceXmlParser
{
    private const array HEADER_FIELDS = ['Id', 'Date', 'GroupIndex', 'NumGroups', 'NumRecs'];

    public function parseFile(string $path): InventoryBalance
    {
        if (! is_readable($path)) {
            throw new ValidationException('Yusen inventory file is not readable: ' . $path);
        }

        $reader = new XMLReader();

        if (@$reader->open($path) === false) {
            throw new ValidationException('Could not open Yusen inventory file: ' . $path);
        }

        return $this->consume($reader);
    }

    public function parseString(string $xml): InventoryBalance
    {
        if (trim($xml) === '') {
            throw new ValidationException('Yusen inventory payload is empty');
        }

        $reader = new XMLReader();

        if (@$reader->XML($xml) === false) {
            throw new ValidationException('Could not read Yusen inventory payload');
        }

        return $this->consume($reader);
    }

    private function consume(XMLReader $reader): InventoryBalance
    {
        $previous = libxml_use_internal_errors(true);

        /** @var array<string, InventoryBalanceLine> $lines */
        $lines = [];
        $header = [];
        $warehouseCodes = [];
        $totalRecords = 0;

        // Manhattan repeats an inventory record across groups when a document is split; InternalID
        // is the record's identity, so a repeat must not be counted twice into the same item.
        $seenInternalIds = [];

        try {
            while (@$reader->read()) {
                if ($reader->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if (in_array($reader->localName, self::HEADER_FIELDS, true) && ! isset($header[$reader->localName])) {
                    $header[$reader->localName] = $reader->readString();

                    continue;
                }

                if ($reader->localName !== 'Inventory') {
                    continue;
                }

                // `readOuterXml()` already parsed the whole record, so walking into its children
                // (a serialised item carries one <SerialNumber> subtree per unit) would traverse
                // the document twice. `next()` jumps straight to the following sibling record.
                do {
                    $element = $this->toElement($reader->readOuterXml());

                    if ($element === null) {
                        continue;
                    }

                    $internalId = $this->str($element->InternalID);

                    if ($internalId !== null && isset($seenInternalIds[$internalId])) {
                        continue;
                    }

                    if ($internalId !== null) {
                        $seenInternalIds[$internalId] = true;
                    }

                    $this->absorb($element, $lines, $warehouseCodes);
                    $totalRecords++;
                } while (@$reader->next('Inventory'));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new InventoryBalance(
            externalId: $header['Id'] ?? null,
            generatedAt: $this->date($header['Date'] ?? null),
            groupIndex: isset($header['GroupIndex']) ? (int) $header['GroupIndex'] : null,
            numGroups: isset($header['NumGroups']) ? (int) $header['NumGroups'] : null,
            declaredRecords: isset($header['NumRecs']) ? (int) $header['NumRecs'] : null,
            totalRecords: $totalRecords,
            lines: $lines,
            warehouseCodes: array_values(array_unique($warehouseCodes)),
        );
    }

    /**
     * @param array<string, InventoryBalanceLine> $lines
     * @param array<array-key, string> $warehouseCodes
     */
    private function absorb(SimpleXMLElement $element, array &$lines, array &$warehouseCodes): void
    {
        $sku = $element->SKU;
        $item = $this->str($sku->Item);

        if ($item === null) {
            return;
        }

        $warehouseCode = $this->str($element->Warehouse) ?? 'UNKNOWN';
        $warehouseCodes[] = $warehouseCode;

        $key = $item . '|' . $warehouseCode;

        $lines[$key] ??= new InventoryBalanceLine(item: $item, warehouseCode: $warehouseCode);

        $lines[$key]->addRecord(
            $this->float($sku->Quantity),
            $this->float($element->AllocatedQty),
            $this->float($element->InTransitQty),
            $this->float($element->SuspenseQty),
            $this->str($element->Status),
        );

        $lines[$key]->describeFrom(
            $this->str($sku->Desc),
            $this->str($sku->Style),
            $this->str($sku->Color),
            $this->str($sku->Size),
        );
    }

    /**
     * `readOuterXml()` re-emits the node with the document's default namespace attached, which
     * would push every child behind `->children($ns)`. Dropping the declaration keeps the access
     * plain — nothing here is namespace-sensitive, the element names are already unambiguous.
     */
    private function toElement(string $xml): ?SimpleXMLElement
    {
        if (trim($xml) === '') {
            return null;
        }

        $xml = (string) preg_replace('/\sxmlns(:\w+)?="[^"]*"/', '', $xml);

        try {
            $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NOCDATA | LIBXML_NOBLANKS);
        } catch (Throwable) {
            return null;
        }

        return $element === false ? null : $element;
    }

    /**
     * Takes `mixed` on purpose. A *direct* missing child (`$inventory->Status`) comes back as an
     * empty SimpleXMLElement, but a missing child of a missing parent (`$sku->Item` where `<SKU>`
     * itself is absent) comes back as null — so the guard is load-bearing, not defensive noise.
     */
    private function str(mixed $value): ?string
    {
        if (! $value instanceof SimpleXMLElement) {
            return null;
        }

        return Str::trimToNull((string) $value);
    }

    private function float(mixed $value): float
    {
        return $value instanceof SimpleXMLElement ? (float) trim((string) $value) : 0.0;
    }

    private function date(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
