<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Souk\Orders\Models\OrderStatus;
use Kanvas\Souk\Orders\Models\OrderTypes;

class CalculateRoadsideAssistanceMetricsAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly ?string $startDate = null,
        protected readonly ?string $endDate = null,
        protected readonly ?int $providerCompanyId = null,
        protected readonly ?int $companyId = null,
    ) {
    }

    public function execute(): array
    {
        $orderTypeIds = OrderTypes::where('apps_id', $this->app->getId())
            ->where('name', OrderTypeEnum::ROADSIDE_ASSISTANCE->value)
            ->pluck('id')
            ->all();

        $start = $this->startDate !== null
            ? Carbon::parse($this->startDate)->startOfDay()
            : Carbon::now()->subDays(30)->startOfDay();
        $end = $this->endDate !== null
            ? Carbon::parse($this->endDate)->endOfDay()
            : Carbon::now()->endOfDay();

        $period = [
            'start' => $start->toDateTimeString(),
            'end' => $end->toDateTimeString(),
        ];

        if ($orderTypeIds === []) {
            return $this->emptyMetrics($period);
        }

        $statusMap = OrderStatus::whereIn('order_types_id', $orderTypeIds)
            ->whereIn('slug', [
                MovipassOrderStatusEnum::SERVICE_COMPLETED->slug(),
                MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug(),
                MovipassOrderStatusEnum::SERVICE_CANCELLED->slug(),
                MovipassOrderStatusEnum::ON_SITE->slug(),
            ])
            ->get(['id', 'slug'])
            ->groupBy('slug')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $resolvedIds = $statusMap[MovipassOrderStatusEnum::SERVICE_COMPLETED->slug()] ?? [];
        $notResolvedIds = $statusMap[MovipassOrderStatusEnum::SERVICE_COMPLETED_NOT_RESOLVED->slug()] ?? [];
        $cancelledIds = $statusMap[MovipassOrderStatusEnum::SERVICE_CANCELLED->slug()] ?? [];
        $onSiteIds = $statusMap[MovipassOrderStatusEnum::ON_SITE->slug()] ?? [];

        $db = DB::connection('commerce');

        $buildSubquery = fn (array $statusIds) => $db->table('order_transitions_history')
            ->select('order_id', DB::raw('MIN(changed_at) AS changed_at'))
            ->whereIn('to_status_id', $statusIds !== [] ? $statusIds : [0])
            ->where('is_deleted', 0)
            ->groupBy('order_id');

        $query = $db->table('orders as o')
            ->leftJoinSub($buildSubquery($resolvedIds), 'resolved', 'resolved.order_id', '=', 'o.id')
            ->leftJoinSub($buildSubquery($notResolvedIds), 'not_resolved', 'not_resolved.order_id', '=', 'o.id')
            ->leftJoinSub($buildSubquery($cancelledIds), 'cancelled', 'cancelled.order_id', '=', 'o.id')
            ->leftJoinSub($buildSubquery($onSiteIds), 'on_site', 'on_site.order_id', '=', 'o.id')
            ->where('o.apps_id', $this->app->getId())
            ->where('o.is_deleted', 0)
            ->whereIn('o.order_types_id', $orderTypeIds)
            ->whereBetween('o.created_at', [$start, $end]);

        if ($this->companyId !== null) {
            $query->where('o.companies_id', $this->companyId);
        }

        if ($this->providerCompanyId !== null) {
            $providerId = $this->providerCompanyId;
            $query->where(function ($q) use ($providerId) {
                $q->whereRaw(
                    "CAST(JSON_EXTRACT(o.metadata, '$.assistance_case.mechanic.company_id') AS UNSIGNED) = ?",
                    [$providerId]
                )->orWhereRaw(
                    "CAST(JSON_EXTRACT(o.metadata, '$.data.assistance_case.mechanic.company_id') AS UNSIGNED) = ?",
                    [$providerId]
                );
            });
        }

        $row = $query->selectRaw(
            'COUNT(*) AS total_cases,
             SUM(CASE WHEN resolved.changed_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved_cases,
             SUM(CASE WHEN not_resolved.changed_at IS NOT NULL THEN 1 ELSE 0 END) AS unresolved_completed_cases,
             SUM(CASE WHEN cancelled.changed_at IS NOT NULL THEN 1 ELSE 0 END) AS cancelled_cases,
             AVG(TIMESTAMPDIFF(SECOND, o.created_at, resolved.changed_at)) AS avg_resolution_seconds,
             AVG(TIMESTAMPDIFF(SECOND, o.created_at, on_site.changed_at)) AS avg_response_seconds'
        )->first();

        $totalCases = (int) ($row->total_cases ?? 0);
        $resolvedCases = (int) ($row->resolved_cases ?? 0);
        $unresolvedCompletedCases = (int) ($row->unresolved_completed_cases ?? 0);
        $cancelledCases = (int) ($row->cancelled_cases ?? 0);
        $avgResolutionSeconds = $row->avg_resolution_seconds !== null ? (float) $row->avg_resolution_seconds : null;
        $avgResponseSeconds = $row->avg_response_seconds !== null ? (float) $row->avg_response_seconds : null;

        return [
            'period' => $period,
            'totalCases' => $totalCases,
            'resolvedCases' => $resolvedCases,
            'unresolvedCompletedCases' => $unresolvedCompletedCases,
            'cancelledCases' => $cancelledCases,
            'avgResolutionSeconds' => $avgResolutionSeconds,
            'avgResolutionHours' => $avgResolutionSeconds !== null ? round($avgResolutionSeconds / 3600, 2) : null,
            'avgResponseSeconds' => $avgResponseSeconds,
            'avgResponseHours' => $avgResponseSeconds !== null ? round($avgResponseSeconds / 3600, 2) : null,
        ];
    }

    private function emptyMetrics(array $period): array
    {
        return [
            'period' => $period,
            'totalCases' => 0,
            'resolvedCases' => 0,
            'unresolvedCompletedCases' => 0,
            'cancelledCases' => 0,
            'avgResolutionSeconds' => null,
            'avgResolutionHours' => null,
            'avgResponseSeconds' => null,
            'avgResponseHours' => null,
        ];
    }
}
