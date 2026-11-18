<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Queries;

use App\GraphQL\Concerns\ResolvesActingContext;
use App\GraphQL\Connector\Movipass\Concerns\ResolvesRegionalRoadsideOrder;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideEvidenceRules;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderOutbox;
use Kanvas\Connectors\Movipass\Support\RoadsideSlaCalculator;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Souk\Orders\Models\Order;

class RoadsideRegionalQuery
{
    use InteractsWithAssistanceCase;
    use ResolvesActingContext;
    use ResolvesRegionalRoadsideOrder;

    private const AUDIT_LIMIT = 1000;

    public function auditLog(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        return $this->auditEntries(RoadsideAuditTrail::ORDER_ENTITY, $order->getId(), $ctx->app->getId());
    }

    public function tariffAuditLog(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return $this->auditEntries(RoadsideAuditTrail::TARIFF_ENTITY, null, $ctx->app->getId());
    }

    public function sla(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        return RoadsideSlaCalculator::forCase($this->assistanceCaseFrom($order), RegionalAssistance::slaThresholds($order));
    }

    /**
     * Analytics are scoped to the acting app and company, like every aggregate in this codebase.
     */
    public function slaSummary(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $totals = [];

        Order::query()
            ->fromApp($ctx->app)
            ->fromCompany($ctx->company)
            ->notDeleted()
            ->whereHas('orderType', fn ($query) => $query->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value))
            ->whereNotNull('metadata->assistance_case->state')
            ->whereBetween('created_at', [Carbon::parse($request['from']), Carbon::parse($request['to'])])
            ->chunkById(200, function ($orders) use (&$totals) {
                foreach ($orders as $order) {
                    $metrics = RoadsideSlaCalculator::forCase($this->assistanceCaseFrom($order), RegionalAssistance::slaThresholds($order));

                    foreach ($metrics as $metric) {
                        $totals[$metric['metric']]['count'] = ($totals[$metric['metric']]['count'] ?? 0) + 1;
                        $totals[$metric['metric']]['minutes'] = ($totals[$metric['metric']]['minutes'] ?? 0) + $metric['minutes'];
                        $totals[$metric['metric']]['breached'] = ($totals[$metric['metric']]['breached'] ?? 0) + (int) $metric['breached'];
                    }
                }
            });

        return array_map(static fn (string $metric, array $total) => [
            'metric' => $metric,
            'cases' => $total['count'],
            'average_minutes' => round($total['minutes'] / max($total['count'], 1), 1),
            'breached' => $total['breached'],
        ], array_keys($totals), $totals);
    }

    public function providerSync(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $order = $this->regionalRoadsideOrder($ctx->app, $request['order_id']);

        return [
            'last_error' => $order->get(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_ERROR->value),
            'events' => RoadsideProviderOutbox::all($order),
        ];
    }

    public function evidence(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return RoadsideEvidenceRules::summary(
            $this->assistanceCaseFrom($this->regionalRoadsideOrder($ctx->app, $request['order_id'])),
        );
    }

    public function tariffVersions(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();

        return RegionalAssistance::tariffVersions($ctx->app, $ctx->company);
    }

    private function auditEntries(string $entityType, ?int $entityId, int $appId): array
    {
        return Event::query()
            ->where('apps_id', $appId)
            ->where('source_domain', RoadsideAuditTrail::SOURCE_DOMAIN)
            ->where('source_entity_type', $entityType)
            ->when($entityId !== null, fn ($query) => $query->where('source_entity_id', $entityId))
            ->orderByDesc('id')
            ->limit(self::AUDIT_LIMIT)
            ->get()
            ->map(fn (Event $event) => [
                'id' => $event->uuid,
                'occurred_at' => $event->occurred_at?->toISOString(),
                'event_type' => $event->event_type,
                'result' => $event->status,
                'actor_type' => $event->actor_type,
                'actor_id' => $event->actor_id,
                'actor_name' => $event->payload['actor_name'] ?? null,
                'module' => $event->payload['module'] ?? null,
                'action' => $event->payload['action'] ?? null,
                'channel' => $event->payload['channel'] ?? null,
                'reason' => $event->payload['reason'] ?? null,
                'before' => $event->payload['before'] ?? [],
                'after' => $event->payload['after'] ?? [],
                'ip' => $event->payload['ip'] ?? null,
                'device' => $event->payload['device'] ?? null,
            ])
            ->all();
    }
}
