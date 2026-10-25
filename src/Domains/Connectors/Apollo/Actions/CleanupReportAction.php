<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo\Actions;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
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
 *
 * `byCompany` is the EMPLOYER split; `byTenantCompany` is the Kanvas-company split. Different axes.
 */
class CleanupReportAction
{
    private const string EVENT_TYPE = 'people.enriched';
    private const string SOURCE_DOMAIN = 'Guild';

    public function __construct(
        protected Apps $app,
        protected CompanyInterface|CompanyScope $company,
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

        $base = $this->analyticsQuery($this->company);

        $verified = $service->countDistinctEntities($base);
        $total = $this->totalPeople($this->company);

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
            'verifiedPeople' => $verified,
            'totalPeople' => $total,
            'verifiedPct' => $total > 0 ? round((float) $verified / (float) $total * 100.0, 1) : 0.0,
            'changedCompany' => $this->peopleWith($byKey, 'changes.current_employer'),
            'changedTitle' => $this->peopleWith($byKey, 'changes.title'),
            'changedEmail' => $this->peopleWith($byKey, 'changes.email_changed'),
            'promotedToDecisionMaker' => $this->peopleWith($byKey, 'changes.seniority_promoted'),
            'newAccounts' => $this->peopleWith($byKey, 'changes.new_account'),
            'bouncingPeople' => $this->bouncingPeople($this->company),
            'byCompany' => $this->companyBreakdown(),
            ...$this->tenantBreakdown($service),
        ];
    }

    private function analyticsQuery(CompanyInterface|CompanyScope $company): EventAnalyticsQuery
    {
        return new EventAnalyticsQuery(
            app: $this->app,
            company: $company,
            eventTypes: [self::EVENT_TYPE],
            sourceDomain: self::SOURCE_DOMAIN,
            sourceEntityType: People::class,
            from: $this->from,
            to: $this->to,
        );
    }

    private function totalPeople(CompanyInterface|CompanyScope $company): int
    {
        return (int) People::query()->fromApp($this->app)->fromCompany($company)->notDeleted()->count();
    }

    /**
     * @return array{byTenantCompany: list<array<string, mixed>>}|array{}
     */
    private function tenantBreakdown(EventAnalyticsService $service): array
    {
        if (! CompanyScope::needsTenantLabel($this->company)) {
            return [];
        }

        $rows = [];

        foreach (Companies::query()->whereIn('id', $this->company->companyIds)->orderBy('name')->get() as $company) {
            $verified = $service->countDistinctEntities($this->analyticsQuery($company));
            $total = $this->totalPeople($company);

            $rows[] = [
                'tenant_company_id' => (int) $company->getId(),
                'tenant_company' => (string) $company->name,
                'totalPeople' => $total,
                'verifiedPeople' => $verified,
                'verifiedPct' => $total > 0 ? round((float) $verified / (float) $total * 100.0, 1) : 0.0,
                'bouncingPeople' => $this->bouncingPeople($company),
            ];
        }

        return ['byTenantCompany' => $rows];
    }

    /**
     * People whose email is a dead address (hard bounce / invalid) — flagged for
     * replacement. Independent of the ledger: it reads the live contact flag.
     */
    private function bouncingPeople(CompanyInterface|CompanyScope $company): int
    {
        return (int) People::query()
            ->fromApp($this->app)
            ->fromCompany($company)
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
     * Distinct people (not events) carrying a payload key — "758 people", not "758 events".
     *
     * @param Collection<array-key, \Kanvas\NervousSystem\Ledger\DataTransferObject\EventAnalyticsBucket> $byKey
     */
    private function peopleWith(Collection $byKey, string $key): int
    {
        return $byKey->get($key)?->distinctEntities ?? 0;
    }

    /**
     * Top employers split by freshness, grouped entirely within the ledger via the
     * `company` we stamp on each people.enriched event. A person is "outdated" when any
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
