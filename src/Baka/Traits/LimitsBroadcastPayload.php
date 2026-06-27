<?php

declare(strict_types=1);

namespace Baka\Traits;

trait LimitsBroadcastPayload
{
    /**
     * Default soft cap on a single field. Leaves ~2 KB of slack under
     * Pusher's 10,240-byte total for the rest of the envelope (channel
     * metadata, ids, timestamps, etc.).
     */
    public const int DEFAULT_MAX_BROADCAST_PAYLOAD_BYTES = 8192;

    protected function limitBroadcastPayload(
        mixed $value,
        int $maxBytes = self::DEFAULT_MAX_BROADCAST_PAYLOAD_BYTES,
    ): mixed {
        if ($value === null) {
            return null;
        }

        $bytes = strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $bytes > $maxBytes ? null : $value;
    }

    /**
     * Caps several unbounded fields against a SHARED budget. Capping each field
     * on its own still blows Pusher's cap when two of them are individually under
     * the limit but jointly over it (e.g. a large user message AND a large LLM
     * response). Fields are nulled largest-first until the combined JSON size
     * fits; clients that see a null field refetch the full row.
     *
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    protected function limitBroadcastPayloadSet(
        array $fields,
        int $maxBytes = self::DEFAULT_MAX_BROADCAST_PAYLOAD_BYTES,
    ): array {
        $sizeOf = static fn (mixed $value): int => $value === null
            ? 0
            : strlen((string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        while (array_sum(array_map($sizeOf, $fields)) > $maxBytes) {
            $largestKey = null;
            $largestSize = 0;
            foreach ($fields as $key => $value) {
                $size = $sizeOf($value);
                if ($value !== null && $size > $largestSize) {
                    $largestKey = $key;
                    $largestSize = $size;
                }
            }

            if ($largestKey === null) {
                break;
            }

            $fields[$largestKey] = null;
        }

        return $fields;
    }
}
