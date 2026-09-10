<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Odoo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Odoo\Actions\PullAllLeadsAction;
use Kanvas\Connectors\Odoo\Actions\PullAllOrganizationsAction;
use Kanvas\Connectors\Odoo\Actions\PullAllPeopleAction;
use Kanvas\Connectors\Odoo\Jobs\OdooBackfillImportJob;

/**
 * Bulk-pulls Organizations/People/Leads from a company's Odoo instance and dispatches
 * `OdooBackfillImportJob` to upsert them into Kanvas via the existing `Pull*Action` pipeline.
 * Same shape as `SalesforceBackfillCommand`.
 *
 * Recommended (not enforced): run Organization before People — `PullPeopleAction` links a person
 * to its Organization only if that Organization is already synced; running People first just
 * skips the link, it doesn't fail.
 */
class OdooBackfillCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:odoo-backfill {app_id} {company_id} {--objects=Organization,People,Lead}';

    protected $description = 'Bulk-pull Odoo Organizations/People/Leads and queue them for import into Kanvas';

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
                'Organization' => $this->backfillOrganizations($app, $company),
                'People' => $this->backfillPeople($app, $company),
                'Lead' => $this->backfillLeads($app, $company),
                default => $this->warn("Skipping unsupported object: {$object}"),
            };
        }

        return self::SUCCESS;
    }

    private function backfillOrganizations(Apps $app, Companies $company): void
    {
        $records = new PullAllOrganizationsAction($app, $company)->execute();

        $this->line('Pulled ' . count($records) . " Organization(s) from Odoo for company {$company->name}");

        if (count($records) > 0) {
            OdooBackfillImportJob::dispatch($app, $company, 'Organization', $records);
        }
    }

    private function backfillPeople(Apps $app, Companies $company): void
    {
        $records = new PullAllPeopleAction($app, $company)->execute();

        $this->line('Pulled ' . count($records) . " People from Odoo for company {$company->name}");

        if (count($records) > 0) {
            OdooBackfillImportJob::dispatch($app, $company, 'People', $records);
        }
    }

    private function backfillLeads(Apps $app, Companies $company): void
    {
        $records = new PullAllLeadsAction($app, $company)->execute();

        $this->line('Pulled ' . count($records) . " Lead(s) from Odoo for company {$company->name}");

        if (count($records) > 0) {
            OdooBackfillImportJob::dispatch($app, $company, 'Lead', $records);
        }
    }
}
