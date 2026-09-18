<?php

declare(strict_types=1);

namespace App\Console\Commands\Ecosystem\Companies;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\CorporateApplications\Actions\FlagOverdueCorporateApplicationsAction;
use Kanvas\Guild\Leads\Models\Lead;

class FlagOverdueCorporateApplicationsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas-company:flag-overdue-applications {app_id?}';

    protected $description = 'Stamp corporate/parking applications past their review SLA and escalate them to the receiver team';

    public function handle(): void
    {
        $appIds = $this->argument('app_id')
            ? [(int) $this->argument('app_id')]
            : $this->appsWithOpenApplications();

        foreach ($appIds as $appId) {
            $app = Apps::getById($appId);
            $this->overwriteAppService($app);

            $flagged = new FlagOverdueCorporateApplicationsAction($app)->execute();

            $this->info("App {$app->getId()} ({$app->name}): " . count($flagged) . ' overdue');
        }
    }

    private function appsWithOpenApplications(): array
    {
        $open = FlagOverdueCorporateApplicationsAction::openApplicationIds();

        if ($open === []) {
            return [];
        }

        return Lead::query()
            ->whereIn('id', $open)
            ->notDeleted()
            ->distinct()
            ->pluck('apps_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
