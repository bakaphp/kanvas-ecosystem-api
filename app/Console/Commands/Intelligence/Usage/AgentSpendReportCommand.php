<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Usage;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Services\ModelPricingCalculator;

/**
 * Ecosystem-wide token/cost report across every agent runtime, for humans.
 *
 * Usage lands in two different places depending on the runtime, and the split is not the obvious one:
 *
 *  - In-process (Neuron/Laravel) records per-turn into agent_conversation_messages.usage. Read live
 *    here rather than from its nightly rollup, so the current day is included.
 *  - Everything else (OpenClaw, Hermes, hosted Claude) reports into agent_usage_snapshots.
 *
 * The snapshot side is partitioned by `source`, NOT by agent_deployment_id: hosted Claude writes a
 * null deployment, so filtering on the FK drops it silently. Excluding the neuron/laravel sources is
 * what prevents double-counting the rollup of the same turns read live above.
 *
 * Blind spot worth stating out loud: ADK is in-process but metered by Google, so it writes neither
 * side and cannot appear here at all.
 */
class AgentSpendReportCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-intelligence:agent-spend-report
                            {--since= : Y-m-d start (default: first day of the current month)}
                            {--until= : Y-m-d end, inclusive (default: today)}
                            {--app= : Limit to one apps_id (default: every app)}
                            {--group=agent : Row grouping — agent, app, company, model or runtime}
                            {--limit=40 : Rows to print; 0 prints every row}';

    protected $description = 'Token + cost consumption for every agent across every runtime, by app and company.';

    private const array GROUPINGS = ['agent', 'app', 'company', 'model', 'runtime'];

    public function handle(): int
    {
        $group = (string) $this->option('group');

        if (! in_array($group, self::GROUPINGS, true)) {
            $this->error(sprintf('Unknown --group "%s". Valid: %s', $group, implode(', ', self::GROUPINGS)));

            return self::FAILURE;
        }

        $since = $this->option('since') ? Carbon::parse((string) $this->option('since')) : Carbon::now()->startOfMonth();
        $until = $this->option('until') ? Carbon::parse((string) $this->option('until')) : Carbon::now();
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;

        if ($appId !== null) {
            // Single-app mode is the only path resolving a concrete app; bind it so nothing
            // Bouncer-scoped downstream inherits a previous process's scope.
            /** @var Apps $app */
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);
        }

        $rows = array_merge(
            $this->inProcessUsage($since, $until, $appId),
            $this->runtimeUsage($since, $until, $appId),
        );

        if ($rows === []) {
            $this->warn(sprintf('No agent usage recorded between %s and %s.', $since->toDateString(), $until->toDateString()));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<info>Agent spend %s → %s</info>%s',
            $since->toDateString(),
            $until->toDateString(),
            $appId !== null ? " (app {$appId})" : ' (all apps)'
        ));

        $this->renderGrouped($rows, $group);
        $this->renderRuntimeSummary($rows);
        $this->renderTotals($rows);

        return self::SUCCESS;
    }

    /**
     * Neuron/Laravel, read straight from the turns so today is included. The nightly rollup of this
     * same data is deliberately not used — it lags a day and adds nothing here.
     *
     * @return list<array<string, mixed>>
     */
    private function inProcessUsage(Carbon $since, Carbon $until, ?int $appId): array
    {
        $query = DB::connection('intelligence')
            ->table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->leftJoin('agents as a', 'a.id', '=', 'c.agent_id')
            ->where('m.created_at', '>=', $since->copy()->startOfDay())
            ->where('m.created_at', '<', $until->copy()->startOfDay()->addDay())
            ->groupBy('c.apps_id', 'c.agent_id', 'a.name', 'a.companies_id', 'c.companies_id', 'model')
            ->selectRaw('c.apps_id, c.agent_id, a.name as agent_name, COALESCE(a.companies_id, c.companies_id) as companies_id')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(m.`usage`, '$.model')) as model")
            ->selectRaw('COUNT(*) as turns')
            ->selectRaw('COUNT(DISTINCT c.id) as sessions')
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.prompt_tokens'), JSON_EXTRACT(m.`usage`, '$.input_tokens'), 0) AS UNSIGNED)), 0) as input_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.completion_tokens'), JSON_EXTRACT(m.`usage`, '$.output_tokens'), 0) AS UNSIGNED)), 0) as output_tokens")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_read_input_tokens'), JSON_EXTRACT(m.`usage`, '$.cache_read'), 0) AS UNSIGNED)), 0) as cache_read")
            ->selectRaw("COALESCE(SUM(CAST(COALESCE(JSON_EXTRACT(m.`usage`, '$.cache_write_input_tokens'), JSON_EXTRACT(m.`usage`, '$.cache_write'), 0) AS UNSIGNED)), 0) as cache_write");

        if ($appId !== null) {
            $query->where('c.apps_id', $appId);
        }

        $calculator = app(ModelPricingCalculator::class);

        return $query->get()->map(fn (object $row): array => $this->normalize(
            row: $row,
            runtime: 'in-process',
            cost: $row->model !== null ? $calculator->costFor(
                ModelPricingCalculator::inferProvider((string) $row->model),
                (string) $row->model,
                (int) $row->input_tokens,
                (int) $row->output_tokens,
                (int) $row->cache_read,
                (int) $row->cache_write,
                $until,
            ) : 0.0,
        ))->all();
    }

    /**
     * OpenClaw, Hermes and hosted Claude. Their collectors already computed a cost_usd — runtime
     * reported where available, model_pricing otherwise — so it is used as-is rather than re-priced.
     *
     * @return list<array<string, mixed>>
     */
    private function runtimeUsage(Carbon $since, Carbon $until, ?int $appId): array
    {
        $query = DB::connection('intelligence')
            ->table('agent_usage_snapshots as s')
            ->leftJoin('agents as a', 'a.id', '=', 's.agent_id')
            ->where('s.is_deleted', 0)
            ->whereNotIn('s.source', AgentProviderEnum::localUsageProviderValues())
            ->whereBetween('s.snapshot_date', [$since->toDateString(), $until->toDateString()])
            ->groupBy('s.apps_id', 's.agent_id', 'a.name', 's.companies_id', 's.source', 's.model')
            ->selectRaw('s.apps_id, s.agent_id, a.name as agent_name, s.companies_id, s.source, s.model')
            ->selectRaw('0 as turns')
            ->selectRaw('COALESCE(SUM(s.total_sessions), 0) as sessions')
            ->selectRaw('COALESCE(SUM(s.input_tokens), 0) as input_tokens')
            ->selectRaw('COALESCE(SUM(s.output_tokens), 0) as output_tokens')
            ->selectRaw('COALESCE(SUM(s.cost_usd), 0) as cost_usd');

        if ($appId !== null) {
            $query->where('s.apps_id', $appId);
        }

        return $query->get()->map(fn (object $row): array => $this->normalize(
            row: $row,
            runtime: (string) $row->source,
            cost: (float) $row->cost_usd,
        ))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function normalize(object $row, string $runtime, float $cost): array
    {
        return [
            'apps_id' => (int) $row->apps_id,
            'companies_id' => (int) ($row->companies_id ?? 0),
            'agent_id' => (int) ($row->agent_id ?? 0),
            'agent_name' => $row->agent_name,
            'runtime' => $runtime,
            'model' => $row->model,
            'turns' => (int) $row->turns,
            'sessions' => (int) $row->sessions,
            'tokens' => (int) $row->input_tokens + (int) $row->output_tokens,
            'cost' => $cost,
        ];
    }

    /**
     * Cost-descending buckets keyed by whatever $keyFor returns. Shared by the grouped table and the
     * runtime summary so the two views can never drift in how they sum the same rows.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array<string, mixed>>
     */
    private function aggregate(array $rows, callable $keyFor): array
    {
        $buckets = [];

        foreach ($rows as $row) {
            $key = $keyFor($row);

            $buckets[$key] ??= [
                'apps_id' => $row['apps_id'],
                'companies_id' => $row['companies_id'],
                'agent' => $row['agent_name'] ?? ($row['agent_id'] !== 0 ? "orphan #{$row['agent_id']}" : '** UNATTRIBUTED **'),
                'runtimes' => [],
                'models' => [],
                'agents' => [],
                'turns' => 0,
                'sessions' => 0,
                'tokens' => 0,
                'cost' => 0.0,
            ];

            $buckets[$key]['runtimes'][$row['runtime']] = true;
            $buckets[$key]['models'][$row['model'] ?? '(not recorded)'] = true;

            if ($row['agent_id'] !== 0) {
                $buckets[$key]['agents'][$row['agent_id']] = true;
            }

            $buckets[$key]['turns'] += $row['turns'];
            $buckets[$key]['sessions'] += $row['sessions'];
            $buckets[$key]['tokens'] += $row['tokens'];
            $buckets[$key]['cost'] += $row['cost'];
        }

        usort($buckets, fn (array $a, array $b): int => $b['cost'] <=> $a['cost']);

        return $buckets;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderGrouped(array $rows, string $group): void
    {
        $apps = $this->nameMap(Apps::class, array_column($rows, 'apps_id'));
        $companies = $this->nameMap(Companies::class, array_column($rows, 'companies_id'));

        $buckets = $this->aggregate($rows, fn (array $row): string => match ($group) {
            'app' => (string) $row['apps_id'],
            'company' => $row['apps_id'] . ':' . $row['companies_id'],
            'model' => $row['model'] ?? '(not recorded)',
            'runtime' => $row['runtime'],
            default => $row['apps_id'] . ':' . ($row['agent_id'] ?: 'none'),
        });

        $limit = (int) $this->option('limit');
        $shown = $limit > 0 ? array_slice($buckets, 0, $limit) : $buckets;

        $headers = match ($group) {
            'app' => ['APP', 'RUNTIMES', 'TURNS', 'SESSIONS', 'TOKENS', 'COST USD'],
            'company' => ['APP', 'COMPANY', 'RUNTIMES', 'TURNS', 'SESSIONS', 'TOKENS', 'COST USD'],
            'model' => ['MODEL', 'RUNTIMES', 'TURNS', 'SESSIONS', 'TOKENS', 'COST USD'],
            'runtime' => ['RUNTIME', 'MODELS', 'TURNS', 'SESSIONS', 'TOKENS', 'COST USD'],
            default => ['APP', 'COMPANY', 'AGENT', 'RUNTIMES', 'MODELS', 'TURNS', 'SESSIONS', 'TOKENS', 'COST USD'],
        };

        $this->table($headers, array_map(
            fn (array $bucket): array => $this->rowFor($bucket, $group, $apps, $companies),
            $shown
        ));

        if ($limit > 0 && count($buckets) > $limit) {
            $this->line(sprintf('  … %d more rows (--limit=0 for all)', count($buckets) - $limit));
        }
    }

    /**
     * @param array<string, mixed> $bucket
     * @param array<int, string>   $apps
     * @param array<int, string>   $companies
     *
     * @return list<string>
     */
    private function rowFor(
        array $bucket,
        string $group,
        array $apps,
        array $companies
    ): array {
        $app = $this->label($apps, $bucket['apps_id']);
        $company = $this->label($companies, $bucket['companies_id']);
        $runtimes = implode(',', array_keys($bucket['runtimes']));
        $models = implode(',', array_keys($bucket['models']));
        $tail = [
            number_format($bucket['turns']),
            number_format($bucket['sessions']),
            number_format($bucket['tokens']),
            number_format($bucket['cost'], 2),
        ];

        return match ($group) {
            'app' => [$app, $runtimes, ...$tail],
            'company' => [$app, $company, $runtimes, ...$tail],
            'model' => [$models, $runtimes, ...$tail],
            'runtime' => [$runtimes, $models, ...$tail],
            default => [$app, $company, $bucket['agent'], $runtimes, $models, ...$tail],
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderRuntimeSummary(array $rows): void
    {
        $buckets = $this->aggregate($rows, fn (array $row): string => $row['runtime']);

        $this->newLine();
        $this->table(
            ['RUNTIME', 'AGENTS', 'TOKENS', 'COST USD'],
            array_map(
                fn (array $bucket): array => [
                    implode(',', array_keys($bucket['runtimes'])),
                    number_format(count($bucket['agents'])),
                    number_format($bucket['tokens']),
                    number_format($bucket['cost'], 2),
                ],
                $buckets
            )
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function renderTotals(array $rows): void
    {
        $unpriced = array_sum(array_map(
            fn (array $r): int => $r['model'] === null ? $r['tokens'] : 0,
            $rows
        ));

        $this->info(sprintf(
            'TOTAL: $%s across %d agents in %d apps',
            number_format(array_sum(array_column($rows, 'cost')), 2),
            count(array_unique(array_filter(array_column($rows, 'agent_id')))),
            count(array_unique(array_column($rows, 'apps_id')))
        ));

        if ($unpriced > 0) {
            $this->warn(sprintf(
                '%s tokens had no model recorded in usage JSON and priced at $0 — the total is a floor.',
                number_format($unpriced)
            ));
        }

        $this->line('<comment>ADK agents are metered by Google and record nothing locally, so they are absent.</comment>');
    }

    /**
     * @param class-string $model
     * @param list<int>    $ids
     *
     * @return array<int, string>
     */
    private function nameMap(string $model, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));

        return $ids === [] ? [] : $model::whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * @param array<int, string> $names
     */
    private function label(array $names, int $id): string
    {
        return $id === 0 ? '—' : ($names[$id] ?? '?') . " ({$id})";
    }
}
