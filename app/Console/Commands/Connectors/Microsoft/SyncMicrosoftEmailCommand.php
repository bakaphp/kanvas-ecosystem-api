<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Microsoft;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Actions\SyncMicrosoftEmailAction;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Throwable;

class SyncMicrosoftEmailCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:sync-microsoft-email {app_id} {--company_id=}';

    protected $description = 'Sync emails from Microsoft Graph for companies with active OAuth tokens';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $companyId = $this->option('company_id');

        if ($companyId !== null) {
            $company = Companies::getById((int) $companyId);
            $this->syncCompany($app, $company);

            return;
        }

        $companies = Companies::fromApp($app)->notDeleted()->get();

        foreach ($companies as $company) {
            $configPrefix = '-' . $app->getId() . '-' . $company->getId();
            $accessToken = $company->get(ConfigurationEnum::ACCESS_TOKEN->value . $configPrefix);

            if (empty($accessToken)) {
                continue;
            }

            $this->syncCompany($app, $company);
        }
    }

    private function syncCompany(Apps $app, Companies $company): void
    {
        $this->info("Syncing Microsoft emails for company: {$company->name} (ID: {$company->getId()})");

        try {
            $user = $company->user;
            $count = count(new SyncMicrosoftEmailAction($app, $company, $user)->execute());

            $this->info("Synced {$count} email(s) for {$company->name}");
        } catch (Throwable $e) {
            $this->error("Failed to sync emails for {$company->name}: {$e->getMessage()}");
            report($e);
        }
    }
}
