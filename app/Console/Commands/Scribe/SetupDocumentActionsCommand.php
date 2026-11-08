<?php

declare(strict_types=1);

namespace App\Console\Commands\Scribe;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Delivery\Support\DocumentActionsSetup;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Users\Models\Users;

class SetupDocumentActionsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'scribe:setup-document-actions
                            {app_id : The app}
                            {company_id? : One company; omitted = every company with Scribe invoices or quotes in the app}';

    protected $description = 'Create the view-invoice / view-quote / sign-sow actions, pipelines and page paths, and seed the dunning schedule. Idempotent.';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        foreach ($this->companies($app) as $company) {
            /** @var Users|null $owner */
            $owner = $company->user;
            if ($owner === null) {
                $this->warn("Company {$company->getId()} has no owner user, skipped.");

                continue;
            }

            new DocumentActionsSetup($app, $owner, $company)->run();

            if (! is_array($company->get(DunningSchedule::SETTING))) {
                $company->set(DunningSchedule::SETTING, DunningSchedule::defaults());
            }

            $this->info("Company {$company->getId()} ({$company->name}): document actions ready.");
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Companies>
     */
    private function companies(Apps $app): Collection
    {
        $companyId = $this->argument('company_id');
        if ($companyId !== null) {
            return collect([Companies::getById((int) $companyId)]);
        }

        $ids = Invoice::query()->where('apps_id', $app->getId())->distinct()->pluck('companies_id')
            ->merge(Quote::query()->where('apps_id', $app->getId())->distinct()->pluck('companies_id'))
            ->unique()
            ->values();

        return Companies::query()->whereIn('id', $ids)->get();
    }
}
