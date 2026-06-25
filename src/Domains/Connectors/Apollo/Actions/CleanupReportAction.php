<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactValidationStatusEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsQuery;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Ledger\Services\EventAnalyticsService;

/**
 * The Apollo cleanup dashboard report. Composes the generic EventAnalyticsService
 * for the headline counts and reads the `people.enriched` payload directly for the
 * per-company "al día vs desactualizado" split — both are domain concerns
 * (payload meaning, company grouping) that don't belong in the generic layer.
 */
class CleanupReportAction
{
    private const string EVENT_TYPE = 'people.enriched';
    private const string SOURCE_DOMAIN = 'Guild';

    public function __construct(
        protected Apps $app,
        protected CompanyInterface $company,
        protected ?Carbon $from = null,
        protected ?Carbon $to = null,
        protected int $topCompanies = 5,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $service = new EventAnalyticsService();

        $base = new EventAnalyticsQuery(
            app: $this->app,
            company: $this->company,
            eventTypes: [self::EVENT_TYPE],
            sourceDomain: self::SOURCE_DOMAIN,
            sourceEntityType: People::class,
            from: $this->from,
            to: $this->to,
        );

        $verified = $service->countDistinctEntities($base);
        $total = (int) People::query()->fromApp($this->app)->fromCompany($this->company)->notDeleted()->count();

        $byKey = $service->countByPayloadKeys($base, [
            'changes.current_employer',
            'changes.title',
            'changes.email_changed',
            'changes.seniority_promoted',
            'changes.new_account',
        ])->keyBy('key');

        return [
            'from' => $this->from?->toDateString(),
            'to' => $this->to?->toDateString(),
            'verifiedLeads' => $verified,
            'totalLeads' => $total,
            'verifiedPct' => $total > 0 ? round((float) $verified / (float) $total * 100.0, 1) : 0.0,
            'changedCompany' => $this->leadsWith($byKey, 'changes.current_employer'),
            'changedTitle' => $this->leadsWith($byKey, 'changes.title'),
            'changedEmail' => $this->leadsWith($byKey, 'changes.email_changed'),
            'promotedToDecisionMaker' => $this->leadsWith($byKey, 'changes.seniority_promoted'),
            'newAccounts' => $this->leadsWith($byKey, 'changes.new_account'),
            'bouncingLeads' => $this->bouncingLeads(),
            'byCompany' => $this->companyBreakdown(),
        ];
    }

    /**
     * Leads whose email is a dead address (hard bounce / invalid) — flagged for
     * replacement. Independent of the ledger: it reads the live contact flag.
     */
    private function bouncingLeads(): int
    {
        return (int) People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas(
                'contacts',
                fn (Builder $query): Builder => $query
                    ->where('contacts_types_id', ContactTypeEnum::EMAIL->value)
                    ->whereIn('validation_status', [
                        ContactValidationStatusEnum::HARD_BOUNCE->value,
                        ContactValidationStatusEnum::INVALID->value,
                    ]),
            )
            ->count();
    }

    /**
     * Distinct leads (not events) carrying a payload key — "758 leads", not "758 events".
     *
     * @param Collection<array-key, \Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsBucket> $byKey
     */
    private function leadsWith(Collection $byKey, string $key): int
    {
        return $byKey->get($key)?->distinctEntities ?? 0;
    }

    /**
     * Top employers split by freshness, grouped entirely within the ledger via the
     * `company` we stamp on each people.enriched event. A lead is "outdated" when any
     * enrichment in range found a change; "al día" when none did.
     *
     * @return list<array{companyName: string, total: int, upToDate: int, outdated: int}>
     */
    private function companyBreakdown(): array
    {
        $rows = Event::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('event_type', self::EVENT_TYPE)
            ->where('source_domain', self::SOURCE_DOMAIN)
            ->when($this->from, fn (Builder $query): Builder => $query->where('occurred_at', '>=', $this->from))
            ->when($this->to, fn (Builder $query): Builder => $query->where('occurred_at', '<=', $this->to))
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.company')) <> ''")
            ->select(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.company')) as company_name"))
            ->selectRaw('COUNT(DISTINCT source_entity_id) as total')
            ->selectRaw("COUNT(DISTINCT CASE WHEN JSON_LENGTH(JSON_EXTRACT(payload, '$.changed_fields')) > 0 THEN source_entity_id END) as outdated")
            ->groupBy('company_name')
            ->orderByDesc('total')
            ->limit($this->topCompanies)
            ->get();

        return $rows->map(function (Event $row): array {
            $total = (int) $row->getAttribute('total');
            $outdated = (int) $row->getAttribute('outdated');

            return [
                'companyName' => (string) $row->getAttribute('company_name'),
                'total' => $total,
                'upToDate' => $total - $outdated,
                'outdated' => $outdated,
            ];
        })->all();
    }
}
