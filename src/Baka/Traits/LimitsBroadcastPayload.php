<?php

declare(strict_types=1);

namespace Baka\Traits;

/**
 * Keeps a single broadcast field under Pusher's hard 10,240-byte per-event
 * limit (https://pusher.com/docs/channels/server_api/http-api#publishing-events).
 *
 * Use from any `ShouldBroadcast` class whose `broadcastWith()` returns
 * a field that can grow unbounded (LLM outputs, tool args, ledger
 * payloads, raw webhook bodies, ...). Pass the value through
 * `limitBroadcastPayload()` — it returns the original value if it fits,
 * or `null` if it would blow the cap. Clients that see `null` refetch
 * the full row by id/uuid.
 */
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
}
