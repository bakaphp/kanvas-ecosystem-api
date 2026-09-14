<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Odoo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Odoo\Actions\PullAllLeadsAction;
use Kanvas\Connectors\Odoo\Actions\PullAllOrganizationsAction;
use Kanvas\Connectors\Odoo\Actions\PullAllPeopleAction;
use Kanvas\Connectors\Odoo\Jobs\OdooBackfillImportJob;

/**
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
            $records = match ($object) {
                'Organization' => new PullAllOrganizationsAction($app, $company)->execute(),
                'People' => new PullAllPeopleAction($app, $company)->execute(),
                'Lead' => new PullAllLeadsAction($app, $company)->execute(),
                default => null,
            };

            if ($records === null) {
                $this->warn("Skipping unsupported object: {$object}");

                continue;
            }

            $this->line(sprintf(
                'Pulled %d %s from Odoo for company %s',
                count($records),
                Str::plural($object, count($records)),
                $company->name,
            ));

            // TODO: chunk this. The whole result set goes into a single queue payload, so a large
            // Odoo instance means a multi-MB Redis entry and an OOM risk in the worker —
            // array_chunk before dispatch. SalesforceBackfillCommand has the same problem.
            if ($records !== []) {
                OdooBackfillImportJob::dispatch(
                    $app,
                    $company,
                    $object,
                    $records,
                );
            }
        }

        return self::SUCCESS;
    }
}
