<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\RollupLocalAgentUsageAction;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Throwable;

/**
 * Daily usage rollup for in-process backends (Neuron, Laravel). These have no
 * deployment row, so the container-runtime collector never sees them — their
 * tokens live in agent_conversation_messages.usage. This sums a day per agent
 * into agent_usage_snapshots so AgentCostService (keyed on agent_id) reports them.
 *
 * Defaults to yesterday so a full day's conversations are settled. Pass --date to
 * backfill a specific day, or {app_id} to scope to one app.
 */
class RollupLocalAgentUsageCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-intelligence:rollup-local-agent-usage
                            {app_id? : Limit to one app (default: every app with local agents)}
                            {--date= : Day to roll up as Y-m-d (default: yesterday)}';

    protected $description = 'Roll up Neuron/Laravel per-message token usage into agent_usage_snapshots for a given day.';

    public function handle(): int
    {
        $dateRaw = $this->option('date');
        $date = is_string($dateRaw) && $dateRaw !== ''
            ? Carbon::parse($dateRaw)
            : Carbon::yesterday();

        $appIdArg = $this->argument('app_id');
        $appIds = $appIdArg !== null
            ? [(int) $appIdArg]
            : $this->appsWithLocalAgents();

        $totalSnapshots = 0;
        $failures = 0;

        foreach ($appIds as $appId) {
            try {
                $app = Apps::getById($appId);
                $this->overwriteAppService($app);

                $snapshots = new RollupLocalAgentUsageAction($app, $date)->execute();
                $totalSnapshots += count($snapshots);
                $this->line(sprintf('app %d: %d agent snapshots for %s', $appId, count($snapshots), $date->toDateString()));
            } catch (Throwable $e) {
                $failures++;
                Log::error('RollupLocalAgentUsageCommand: app failed', [
                    'app_id' => $appId,
                    'date' => $date->toDateString(),
                    'error' => $e->getMessage(),
                ]);
                $this->error(sprintf('app %d FAILED: %s', $appId, $e->getMessage()));
            }
        }

        $this->info(sprintf('Done: %d apps, %d snapshots, %d failed', count($appIds), $totalSnapshots, $failures));

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function appsWithLocalAgents(): array
    {
        return DB::connection('intelligence')
            ->table('agents as a')
            ->join('agent_types as t', 't.id', '=', 'a.agent_type_id')
            ->whereIn('t.provider', AgentProviderEnum::localUsageProviderValues())
            ->where('a.is_deleted', 0)
            ->distinct()
            ->pluck('a.apps_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
