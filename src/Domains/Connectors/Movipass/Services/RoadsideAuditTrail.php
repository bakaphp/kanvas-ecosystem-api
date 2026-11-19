<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Spec v6.0 §11: every action, change, assignment, tariff, payment, incident and piece of evidence
 * leaves a record that can never be edited or removed. The ledger is append-only by construction
 * (ImmutableBaseModel), so a correction is always a new row carrying the old and new values.
 *
 * Writing the trail must never undo the action it describes, so a ledger failure is reported and
 * swallowed.
 */
class RoadsideAuditTrail
{
    public const SOURCE_DOMAIN = 'movipass.roadside';
    public const ORDER_ENTITY = 'order';
    public const TARIFF_ENTITY = 'roadside_tariffs';

    /**
     * @param string $module one of: order, technician, tariff, payment, incident, evidence, tracking, integration
     */
    public static function forOrder(
        Order $order,
        string $module,
        string $action,
        ?Users $actor = null,
        array $before = [],
        array $after = [],
        ?string $reason = null,
        RoadsideAuditChannelEnum $channel = RoadsideAuditChannelEnum::SYSTEM,
        bool $succeeded = true,
        array $details = [],
    ): ?Event {
        if (! RegionalAssistance::isEnabledFor($order)) {
            return null;
        }

        return self::write(
            app: $order->app,
            company: $order->company,
            entityType: self::ORDER_ENTITY,
            entityId: $order->getId(),
            module: $module,
            action: $action,
            actor: $actor,
            payload: self::payload(
                $before,
                $after,
                $reason,
                $channel,
                $details
            ),
            succeeded: $succeeded,
        );
    }

    public static function forTariffs(
        AppInterface $app,
        ?CompanyInterface $company,
        string $action,
        Users $actor,
        array $before,
        array $after,
        string $reason,
        array $details = [],
    ): ?Event {
        return self::write(
            app: $app,
            company: $company,
            entityType: self::TARIFF_ENTITY,
            entityId: $company?->getId() ?? 0,
            module: 'tariff',
            action: $action,
            actor: $actor,
            payload: self::payload(
                $before,
                $after,
                $reason,
                RoadsideAuditChannelEnum::WEB,
                $details
            ),
            succeeded: true,
        );
    }

    private static function payload(
        array $before,
        array $after,
        ?string $reason,
        RoadsideAuditChannelEnum $channel,
        array $details
    ): array {
        $request = app()->runningInConsole() ? null : request();

        return [
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'channel' => $channel->value,
            'ip' => $request?->ip(),
            'device' => $request?->userAgent(),
            'details' => $details,
        ];
    }

    private static function write(
        AppInterface $app,
        ?CompanyInterface $company,
        string $entityType,
        int $entityId,
        string $module,
        string $action,
        ?Users $actor,
        array $payload,
        bool $succeeded,
    ): ?Event {
        try {
            return new AppendEventAction(new EventData(
                app: $app,
                company: $company,
                sourceDomain: self::SOURCE_DOMAIN,
                eventType: self::SOURCE_DOMAIN . '.' . $module . '.' . $action,
                status: $succeeded ? EventStatusEnum::SUCCESS : EventStatusEnum::ERROR,
                sourceEntityType: $entityType,
                sourceEntityId: $entityId,
                actorType: $actor instanceof Users ? 'user' : 'system',
                actorId: $actor?->getId(),
                payload: [
                    'module' => $module,
                    'action' => $action,
                    'actor_name' => $actor instanceof Users ? trim($actor->firstname . ' ' . $actor->lastname) : null,
                    ...$payload,
                ],
            ))->execute();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
