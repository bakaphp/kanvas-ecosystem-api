<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Drift-repair tool for peoples.active_leads_count. The migration that adds
 * the column backfills it once; this command recomputes it from Lead rows
 * for whenever a bulk write (e.g. DB::table('leads')->update(...), or
 * MergePeopleAction's raw SQL people_id rewrite) bypasses
 * LeadObserver::syncActiveLeadsCount() and leaves the counter stale.
 *
 * --companies_branches_id narrows who gets checked, never what gets counted —
 * the counter is per-person across the whole company, so recounting a single
 * branch's leads would write a number lower than the truth.
 */
class RecalculateActiveLeadsCountCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-guild:recalculate-active-leads-count
                            {--apps_id= : Limit to people belonging to this app}
                            {--companies_id= : Limit to people belonging to this company}
                            {--companies_branches_id= : Limit to people who have a lead in this branch (each one is still recounted across all of their open leads)}
                            {--peoples_id=* : Recalculate only these people (repeatable)}
                            {--chunk=500 : People per chunk}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Recompute peoples.active_leads_count from leads (open leads_status_id per company, not deleted)';

    public function handle(): int
    {
        /** @var Apps|null $app */
        $app = $this->option('apps_id') !== null
            ? Apps::getById((int) $this->option('apps_id'))
            : null;

        if ($app !== null) {
            $this->overwriteAppService($app);
        }

        /** @var CompaniesBranches|null $branch */
        $branch = $this->option('companies_branches_id') !== null
            ? CompaniesBranches::getById((int) $this->option('companies_branches_id'))
            : null;

        /** @var Companies|null $company */
        $company = $this->option('companies_id') !== null
            ? Companies::getById((int) $this->option('companies_id'))
            : null;

        if ($branch !== null && $company !== null && (int) $branch->companies_id !== $company->getId()) {
            $this->error(sprintf(
                'Branch %d belongs to company %d, not %d — the run would have checked nobody and reported no drift.',
                $branch->getId(),
                (int) $branch->companies_id,
                $company->getId(),
            ));

            return self::FAILURE;
        }

        $company ??= $branch?->company;

        $dryRun = (bool) $this->option('dry-run');
        $chunk = (int) $this->option('chunk');
        $only = array_map('intval', (array) $this->option('peoples_id'));

        $stats = [
            'people_checked' => 0,
            'mismatches_fixed' => 0,
        ];

        $lastId = 0;

        while (true) {
            $people = People::query()
                ->where('id', '>', $lastId)
                ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
                ->when($app !== null, fn ($query) => $query->fromApp($app))
                ->when($company !== null, fn ($query) => $query->fromCompany($company))
                // Not People::leads(), which filters is_deleted: a bulk soft-delete is
                // the main drift source, so those people are the ones to repair.
                ->when(
                    $branch !== null,
                    fn ($query) => $query->whereIn(
                        'id',
                        Lead::query()
                            ->where('companies_branches_id', $branch->getId())
                            ->where('people_id', '>', 0)
                            ->select('people_id')
                    )
                )
                ->orderBy('id')
                ->limit($chunk)
                ->get(['id', 'companies_id', 'active_leads_count']);

            if ($people->isEmpty()) {
                break;
            }

            $lastId = (int) $people->last()->id;

            $actualCounts = $this->countOpenLeadsPerPeople($people);

            foreach ($people as $person) {
                $stats['people_checked']++;
                $actual = (int) ($actualCounts[$person->id] ?? 0);

                if ($actual === (int) $person->active_leads_count) {
                    continue;
                }

                $stats['mismatches_fixed']++;

                if (! $dryRun) {
                    $person->forceFill(['active_leads_count' => $actual])->saveOrFail();
                }
            }

            $this->line("… {$stats['people_checked']} people, through id {$lastId}");
        }

        $this->table(['metric', 'count'], collect($stats)->map(
            fn ($count, $metric): array => [$metric, $count]
        )->values()->all());

        if ($dryRun) {
            $this->warn('Dry run — nothing was written.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, int> people_id => open lead count
     */
    private function countOpenLeadsPerPeople(Collection $people): array
    {
        $counts = [];

        foreach ($people->groupBy('companies_id') as $companyId => $companyPeople) {
            $company = (int) $companyId > 0 ? Companies::getById((int) $companyId) : null;

            $counts += Lead::query()
                ->whereIn('people_id', $companyPeople->pluck('id'))
                ->hasOpenLeadStatus($company)
                ->where('is_deleted', 0)
                ->selectRaw('people_id, COUNT(*) as total')
                ->groupBy('people_id')
                ->pluck('total', 'people_id')
                ->map(fn ($total): int => (int) $total)
                ->all();
        }

        return $counts;
    }
}
