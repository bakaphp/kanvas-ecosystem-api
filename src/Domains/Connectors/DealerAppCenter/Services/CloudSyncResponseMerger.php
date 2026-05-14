<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerAppCenter\Services;

use Kanvas\Connectors\DealerAppCenter\DataTransferObject\InventoryCsvPayload;

/**
 * Merge per-rooftop InventoryCsvPayloads into the cloud-sync response envelope.
 *
 *   - `fileHeaders` is the union of all rooftops' headers, preserving the
 *     order they were first encountered, with `Rooftop ID` appended last.
 *   - Each row is widened to the unioned header width with `""` for cells
 *     the source CSV didn't define.
 *   - The `Rooftop ID` column is stamped per row from the payload's
 *     `rooftopId` so the consumer's `Rooftops::getByExternalId()` lookup
 *     resolves correctly even on multi-rooftop responses. If a source CSV
 *     already had a `Rooftop ID` column (case-insensitive), that column is
 *     dropped and replaced with ours.
 *   - `fileFirstRow` mirrors `fileValues[0]` (the mapping-UI preview).
 */
class CloudSyncResponseMerger
{
    public const string ROOFTOP_ID_HEADER = 'Rooftop ID';

    /**
     * @param list<InventoryCsvPayload> $payloads
     *
     * @return array{fileHeaders:list<string>,fileFirstRow:list<string>,fileValues:list<list<string>>}
     */
    public function merge(array $payloads): array
    {
        if ($payloads === []) {
            return ['fileHeaders' => [], 'fileFirstRow' => [], 'fileValues' => []];
        }

        $unionHeaders = $this->buildUnionHeaders($payloads);
        $unionWidth = count($unionHeaders);
        $headerIndex = array_flip($unionHeaders);

        $fileValues = [];
        foreach ($payloads as $payload) {
            $columnMap = [];
            foreach ($payload->fileHeaders as $sourceIdx => $name) {
                // Source's own Rooftop ID column (if any) is dropped — we stamp our own at the end.
                $columnMap[$sourceIdx] = $this->isRooftopIdHeader($name)
                    ? null
                    : $headerIndex[$name];
            }

            foreach ($payload->fileValues as $sourceRow) {
                $widened = $unionWidth > 0 ? array_fill(0, $unionWidth, '') : [];
                foreach ($columnMap as $sourceIdx => $targetIdx) {
                    if ($targetIdx === null) {
                        continue;
                    }
                    $widened[$targetIdx] = $sourceRow[$sourceIdx] ?? '';
                }
                // Append the rooftop id as the last column (matches $unionHeaders below).
                $widened[] = $payload->rooftopId;
                $fileValues[] = array_values($widened);
            }
        }

        $unionHeaders[] = self::ROOFTOP_ID_HEADER;

        $fileFirstRow = $fileValues === [] ? [] : $fileValues[0];

        return [
            'fileHeaders' => $unionHeaders,
            'fileFirstRow' => $fileFirstRow,
            'fileValues' => $fileValues,
        ];
    }

    /**
     * @param list<InventoryCsvPayload> $payloads
     *
     * @return list<string>
     */
    private function buildUnionHeaders(array $payloads): array
    {
        $seen = [];
        $ordered = [];

        foreach ($payloads as $payload) {
            foreach ($payload->fileHeaders as $name) {
                if ($this->isRooftopIdHeader($name)) {
                    continue;
                }
                if (! isset($seen[$name])) {
                    $seen[$name] = true;
                    $ordered[] = $name;
                }
            }
        }

        return $ordered;
    }

    private function isRooftopIdHeader(string $name): bool
    {
        return strcasecmp(trim($name), self::ROOFTOP_ID_HEADER) === 0;
    }
}
