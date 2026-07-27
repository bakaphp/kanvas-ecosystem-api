<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Salesforce;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Actions\PullAllOrganizationsAction;
use Kanvas\Connectors\Salesforce\Actions\PullAllPeopleAction;
use Kanvas\Connectors\Salesforce\Jobs\SalesforceBackfillImportJob;

/**
 * Bulk-pulls Accounts/Contacts from a company's Salesforce org and dispatches
 * `SalesforceBackfillImportJob` to upsert them into Kanvas via the existing
 * `PullOrganizationAction`/`PullPeopleAction` pipeline.
 *
 * Recommended (not enforced): run Account before Contact — `PullPeopleAction` links a Contact to
 * its Organization only if that Organization is already synced; running Contact first just skips
 * the link, it doesn't fail.
 *
 * This backfill does not deduplicate — it can create Organizations/People that already exist in
 * Kanvas under a different custom field. That is expected and handled by a later phase.
 */
class SalesforceBackfillCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:salesforce-backfill {app_id} {company_id} {--objects=Account,Contact}';

    protected $description = 'Bulk-pull Salesforce Accounts/Contacts and queue them for import into Kanvas';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $objects = array_filter(array_map('trim', explode(',', (string) $this->option('objects'))));

        foreach ($objects as $object) {
            match ($object) {
                'Account' => $this->backfillOrganizations($app, $company),
                'Contact' => $this->backfillPeople($app, $company),
                default => $this->warn("Skipping unsupported object: {$object}"),
            };
        }

        return self::SUCCESS;
    }

    private function backfillOrganizations(Apps $app, Companies $company): void
    {
        $records = new PullAllOrganizationsAction($app, $company)->execute();

        $this->line('Pulled ' . count($records) . " Account(s) from Salesforce for company {$company->name}");

        if (count($records) > 0) {
            SalesforceBackfillImportJob::dispatch($app, $company, 'Account', $records);
        }
    }

    private function backfillPeople(Apps $app, Companies $company): void
    {
        $records = new PullAllPeopleAction($app, $company)->execute();

        $this->line('Pulled ' . count($records) . " Contact(s) from Salesforce for company {$company->name}");

        if (count($records) > 0) {
            SalesforceBackfillImportJob::dispatch($app, $company, 'Contact', $records);
        }
    }
}
