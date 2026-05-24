<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\DailyLearning\Actions\EnsureAgentReportRoleAction;
use Throwable;

/**
 * Idempotent role bootstrap. Runs the underlying `firstOrCreate` on
 * (name='AgentReport', scope=app_X_company_0), so re-running on each
 * deploy is safe.
 *
 * Default scope is every undeleted app; --app= narrows to one. Run this
 * before any user gets assigned the role via `$user->assign('AgentReport')`.
 */
class EnsureAgentReportRoleCommand extends Command
{
    protected $signature = 'kanvas:nervous-system:ensure-agent-report-role
        {--app= : Restrict to a single apps_id; otherwise applies to every undeleted app}';

    protected $description = 'Ensure the AgentReport Bouncer role exists for the targeted app(s).';

    public function handle(): int
    {
        $query = Apps::query()->where('is_deleted', 0);
        if ($this->option('app') !== null) {
            $query->where('id', (int) $this->option('app'));
        }

        $apps = $query->orderBy('id')->get();

        if ($apps->isEmpty()) {
            $this->warn('No apps matched.');
            return self::SUCCESS;
        }

        $created = 0;
        $failed = 0;

        foreach ($apps as $app) {
            try {
                new EnsureAgentReportRoleAction($app)->execute();
                $this->line(sprintf('  app=%-3d %s → AgentReport role ensured', $app->getId(), $app->name));
                $created++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  app=%d failed: %s', $app->getId(), $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. Ensured %d app(s). Failures: %d', $created, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
