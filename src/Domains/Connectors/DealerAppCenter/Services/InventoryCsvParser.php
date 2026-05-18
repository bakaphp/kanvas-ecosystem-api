<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Services;

use Kanvas\Connectors\DealerAppCenter\DataTransferObject\InventoryCsvPayload;
use League\Csv\Reader;
use Throwable;

/**
 * Parse a raw CSV string into an InventoryCsvPayload.
 *
 * Rules enforced (matching the cloud-sync response contract):
 *   - first line is the header row, never repeated inside `fileValues`
 *   - every cell is a string; empty cells become "" (never null)
 *   - rows shorter than the header are right-padded with "" to header width
 *   - rows longer than the header are truncated (consumer expects parity)
 *   - UTF-8 BOM on the first header is stripped
 *
 * The `Rooftop ID` column is NOT injected here — the merger stamps it as the
 * last column after unioning so it has a stable position across single- and
 * multi-rooftop requests.
 */
class InventoryCsvParser
{
    public function parse(string $rooftopId, string $csvContents): InventoryCsvPayload
    {
        if (trim($csvContents) === '') {
            return new InventoryCsvPayload($rooftopId, [], [], []);
        }

        try {
            $reader = Reader::fromString($csvContents);
            $reader->setHeaderOffset(0);
            $rawHeaders = $reader->getHeader();
        } catch (Throwable) {
            return new InventoryCsvPayload($rooftopId, [], [], []);
        }

        $headers = [];
        foreach ($rawHeaders as $i => $h) {
            $clean = trim($h);
            if ($i === 0) {
                $clean = $this->stripBom($clean);
            }
            $headers[] = $clean;
        }

        $headerWidth = count($headers);

        $fileValues = [];
        foreach ($reader->getRecords() as $record) {
            // Reader::getRecords() without a header arg yields numerically-keyed rows.
            $values = array_values($record);
            $row = [];
            for ($i = 0; $i < $headerWidth; $i++) {
                $row[] = isset($values[$i]) ? (string) $values[$i] : '';
            }
            $fileValues[] = $row;
        }

        if ($fileValues === []) {
            return new InventoryCsvPayload($rooftopId, $headers, [], []);
        }

        return new InventoryCsvPayload(
            rooftopId: $rooftopId,
            fileHeaders: $headers,
            fileFirstRow: $fileValues[0],
            fileValues: $fileValues,
        );
    }

    private function stripBom(string $value): string
    {
        if (str_starts_with($value, "\xEF\xBB\xBF")) {
            return substr($value, 3);
        }

        return $value;
    }
}
