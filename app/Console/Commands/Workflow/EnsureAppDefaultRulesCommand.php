<?php

declare(strict_types=1);

namespace App\Console\Commands\Workflow;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Rules\Actions\EnsureAppDefaultRulesAction;

/**
 * Runs on deploy, after `kanvas:workflow-sync-actions` (the rules point at catalog rows it creates),
 * so every app has the rules for what its companies do on their own, even one with no messaging
 * integration yet.
 */
class EnsureAppDefaultRulesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:workflow-ensure-app-default-rules {app_id? : Only this app}';

    protected $description = 'Install the app-wide workflow rules every company of an app runs.';

    public function handle(): int
    {
        $apps = $this->argument('app_id') !== null
            ? [Apps::getById((int) $this->argument('app_id'))]
            : Apps::query()->where('is_deleted', 0)->get();

        foreach ($apps as $app) {
            $this->overwriteAppService($app);
            $rules = new EnsureAppDefaultRulesAction($app)->execute();
            $this->line(sprintf('%s (%d): %d rules', $app->name, $app->getId(), count($rules)));
        }

        return self::SUCCESS;
    }
}
