<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Support;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Spec v6.0 §13: every event bound for Asistencia Regional is kept with its status, attempts and
 * last error, so a provider outage leaves a retry queue and a trace instead of a lost update.
 *
 * Kept in its own custom field rather than the assistance case: the case is rewritten wholesale by
 * the shared flow, and an outbox entry must not vanish because some caller saved a stale copy.
 */
final class RoadsideProviderOutbox
{
    public const PENDING = 'pending';
    public const SENT = 'sent';
    public const FAILED = 'failed';
    public const SKIPPED = 'skipped';

    // Old entries are dropped from the front; their outcome is already in the audit trail.
    private const MAX_ENTRIES = 200;

    public static function append(Order $order, string $type, array $payload): string
    {
        $id = (string) Str::uuid();
        $entries = self::all($order);
        $entries[] = [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'status' => self::PENDING,
            'attempts' => 0,
            'last_error' => null,
            'created_at' => Carbon::now()->toISOString(),
            'updated_at' => Carbon::now()->toISOString(),
        ];

        self::save($order, array_slice($entries, -self::MAX_ENTRIES));

        return $id;
    }

    public static function find(Order $order, string $id): ?array
    {
        foreach (self::all($order) as $entry) {
            if (($entry['id'] ?? null) === $id) {
                return $entry;
            }
        }

        return null;
    }

    public static function mark(
        Order $order,
        string $id,
        string $status,
        ?string $error = null
    ): void {
        $entries = array_map(static function (array $entry) use ($id, $status, $error): array {
            if (($entry['id'] ?? null) !== $id) {
                return $entry;
            }

            return [
                ...$entry,
                'status' => $status,
                'attempts' => (int) ($entry['attempts'] ?? 0) + (in_array($status, [self::SENT, self::FAILED], true) ? 1 : 0),
                'last_error' => $error,
                'updated_at' => Carbon::now()->toISOString(),
            ];
        }, self::all($order));

        self::save($order, $entries);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function all(Order $order): array
    {
        $entries = $order->get(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_OUTBOX->value);

        if (is_string($entries)) {
            $entries = json_decode($entries, true);
        }

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * @return array<int, string>
     */
    public static function failedIds(Order $order): array
    {
        return array_values(array_map(
            static fn (array $entry) => (string) $entry['id'],
            array_filter(self::all($order), static fn (array $entry) => ($entry['status'] ?? null) === self::FAILED),
        ));
    }

    private static function save(Order $order, array $entries): void
    {
        $order->set(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_OUTBOX->value, array_values($entries));
    }
}
