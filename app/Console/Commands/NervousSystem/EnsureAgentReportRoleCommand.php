<?php

declare(strict_types=1);

namespace App\Console\Commands\NervousSystem;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\NervousSystem\DailyLearning\Actions\EnsureAgentReportRoleAction;
use Throwable;

/**
 * Idempotent role bootstrap. Runs the underlying CreateRoleAction on each
 * matched app — safe to re-run on every deploy.
 *
 * Per-iteration `overwriteAppService()` rebinds both `Apps::class` in the
 * container AND Bouncer's scope to the current app. Without it the static
 * Bouncer scope leaks from the previous app and INSERT hits
 * `roles.name_scope` unique with the prior iteration's scope value.
 */
class EnsureAgentReportRoleCommand extends Command
{
    use KanvasJobsTrait;

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
                $this->overwriteAppService($app);

                $role = new EnsureAgentReportRoleAction($app)->execute();
                $this->line(sprintf(
                    '  ✓ app=%-3d %s role_id=%d',
                    $app->getId(),
                    $app->name,
                    $role->id,
                ));
                $created++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->error(sprintf('  ✗ app=%d %s FAILED: %s', $app->getId(), $app->name, $e->getMessage()));
            }
        }

        $this->info(sprintf('Done. Ensured %d app(s). Failures: %d', $created, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
