<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem\Agents;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Orchestrator\Actions\EnsureCompanyOrchestratorAgentAction;
use Throwable;

/**
 * Provisions (idempotently) one orchestrator per company — a dedicated user, the orchestrator agent, its
 * Inbox project, and the routing receiver. Iterates every app's companies; safe to re-run as a backfill
 * and safe to fire from an app/company-creation hook. Requires the global 'Project Orchestrator'
 * agent-type to be synced first (`kanvas:intelligence:sync-agent-types`).
 */
class EnsureOrchestratorAgentCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:nervous-system:ensure-orchestrator-agent
        {--app= : Only this app id}
        {--company= : Only this company id}
        {--dry-run : List what would be provisioned without writing}';

    protected $description = 'Provision one orchestrator agent + Inbox + routing receiver per company.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $companyFilter = $this->option('company') !== null ? (int) $this->option('company') : null;

        $apps = Apps::query()
            ->where('is_deleted', 0)
            ->when(
                $this->option('app') !== null,
                fn (Builder $query): Builder => $query->where('id', (int) $this->option('app')),
            )
            ->orderBy('id')
            ->get();

        $ensured = 0;
        $failed = 0;
        $seen = 0;

        foreach ($apps as $app) {
            // Per-app scope rebind — agent/channel/role writes must not run under a leaked scope.
            $this->overwriteAppService($app);

            $companyIds = DB::table('user_company_apps')
                ->select('companies_id')
                ->where('apps_id', $app->getId())
                ->distinct();

            Companies::query()
                ->whereIn('id', $companyIds)
                ->when(
                    $companyFilter !== null,
                    fn (Builder $query): Builder => $query->where('id', $companyFilter),
                )
                ->orderBy('id')
                ->each(function (Companies $company) use ($app, $dryRun, &$ensured, &$failed, &$seen): void {
                    $seen++;

                    if ($dryRun) {
                        $this->line(sprintf('  would ensure: app %d company %d', $app->getId(), $company->getId()));

                        return;
                    }

                    try {
                        $agent = new EnsureCompanyOrchestratorAgentAction($app, $company)->execute();
                        $ensured++;
                        $this->line(sprintf('  app %d company %d → agent %d', $app->getId(), $company->getId(), $agent->getId()));
                    } catch (Throwable $e) {
                        $failed++;
                        $this->error(sprintf('  app %d company %d FAILED: %s', $app->getId(), $company->getId(), $e->getMessage()));
                    }
                });
        }

        $this->info(sprintf('Orchestrator provisioning: %d companies seen, %d ensured, %d failed.', $seen, $ensured, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
