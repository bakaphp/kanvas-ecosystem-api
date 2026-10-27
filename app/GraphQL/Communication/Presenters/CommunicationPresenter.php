<?php

declare(strict_types=1);

namespace App\GraphQL\Communication\Presenters;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Operation;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;

/**
 * Shapes the ledger for the schema: status columns are enums on the models and strings in the API.
 */
final class CommunicationPresenter
{
    public static function operation(Operation $operation): array
    {
        return [
            'id' => $operation->id(),
            'status' => $operation->status(),
            'deliveries' => $operation->deliveries()->loadMissing('connection')->map(fn (Delivery $delivery): array => self::delivery($delivery))->all(),
        ];
    }

    public static function delivery(Delivery $delivery): array
    {
        return [
            'id' => $delivery->getId(),
            'channel' => $delivery->channel->value,
            'provider' => $delivery->provider,
            'connection' => $delivery->connection !== null ? self::connection($delivery->connection) : null,
            'to_address' => $delivery->to_address,
            'status' => $delivery->status->value,
            'external_id' => $delivery->external_id,
            'error_code' => $delivery->error_code,
            'error' => $delivery->error,
            'warnings' => $delivery->warnings,
            'attempts' => (int) $delivery->attempts,
            'accepted_at' => $delivery->accepted_at,
            'last_event_at' => $delivery->last_event_at,
        ];
    }

    public static function connection(Connection $connection): array
    {
        $provider = new ProviderRegistry()->all()[$connection->provider] ?? null;

        return [
            'id' => $connection->getId(),
            'uuid' => $connection->uuid,
            'provider' => $connection->provider,
            'account_key' => $connection->account_key,
            'label' => $connection->label,
            'channels' => $provider !== null ? array_keys($provider::transports()) : [],
            'setup_status' => $connection->setup_status->value,
            'health' => $connection->health,
            'enabled' => $connection->enabled,
            'last_error' => $connection->last_error,
            'last_probed_at' => $connection->last_probed_at,
        ];
    }
}
