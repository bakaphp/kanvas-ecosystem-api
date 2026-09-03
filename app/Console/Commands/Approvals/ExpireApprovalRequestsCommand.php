<?php

declare(strict_types=1);

namespace App\Console\Commands\Approvals;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Approvals\Actions\ExpireApprovalRequestsAction;
use Kanvas\Apps\Models\Apps;
use Throwable;

class ExpireApprovalRequestsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:approvals:expire {--apps_id= : Limit the sweep to one app}';

    protected $description = 'Closes approval requests that passed their expires_at without a decision';

    public function handle(): int
    {
        $apps = Apps::query()
            ->when($this->option('apps_id'), fn ($query) => $query->where('id', (int) $this->option('apps_id')))
            ->where('is_deleted', 0)
            ->orderBy('id')
            ->get();

        $total = 0;

        foreach ($apps as $app) {
            // Per iteration, not once at the top: the worker process is long-lived and Bouncer's
            // Scopable auto-appends whatever scope was last bound, so a sweep that touches
            // RoleApproverResolver would resolve the previous app's roles. See root CLAUDE.md.
            $this->overwriteAppService($app);

            try {
                $expired = new ExpireApprovalRequestsAction($app)->execute();
            } catch (Throwable $e) {
                $this->error("App {$app->getId()}: {$e->getMessage()}");

                continue;
            }

            $total += $expired;

            if ($expired > 0) {
                $this->info("App {$app->getId()}: expired {$expired} approval request(s).");
            }
        }

        $this->info("Done. {$total} approval request(s) expired.");

        return self::SUCCESS;
    }
}
