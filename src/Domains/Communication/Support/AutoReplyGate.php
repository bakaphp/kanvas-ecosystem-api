<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Social\Messages\Models\Message;

/**
 * Asked by a responder before it answers an inbound message. The message carries the connection
 * and activation version it arrived under; a connection switched off since — or off and on again —
 * means the turn was queued for a conversation that no longer wants an automatic answer.
 */
final class AutoReplyGate
{
    /**
     * @return string|null why no automatic answer may go out, or null when it may. A message from
     *                     before connections existed carries none, and answers as it always has.
     */
    public static function refusal(Message $inbound): ?string
    {
        $communication = $inbound->message['communication'] ?? null;

        if (! is_array($communication) || ! isset($communication['connection_id'])) {
            return null;
        }

        $connection = Connection::query()->find((int) $communication['connection_id']);

        if ($connection === null) {
            return 'connection_missing';
        }

        $unavailable = $connection->unavailableReason();

        if ($unavailable !== null) {
            return $unavailable;
        }

        $version = $communication['activation_version'] ?? null;

        return $version !== null && (int) $version !== $connection->activation_version
            ? 'stale_activation'
            : null;
    }

    public static function routesThroughCommunication(Message $message): bool
    {
        return isset($message->message['communication']['connection_id']);
    }
}
