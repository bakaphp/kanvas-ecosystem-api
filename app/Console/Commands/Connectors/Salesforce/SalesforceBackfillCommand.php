<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Salesforce;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Imports\Actions\StartIntegrationImportAction;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * Starts the generic integration import for a company's Salesforce Accounts and Contacts.
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

    private const array ENTITIES = [
        'Account' => 'organizations',
        'Contact' => 'people',
    ];

    protected $signature = 'kanvas:salesforce-backfill {app_id} {company_id} {--objects=Account,Contact}';

    protected $description = 'Queue the Salesforce Accounts/Contacts import for a company';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        /** @var Companies $company */
        $company = Companies::getById((int) $this->argument('company_id'));

        $integrationCompany = IntegrationsCompany::getActiveByIntegrationName($company, $app, 'salesforce');

        if ($integrationCompany === null) {
            $this->error("Salesforce is not connected for company {$company->getId()}.");

            return self::FAILURE;
        }

        $objects = array_filter(array_map('trim', explode(',', (string) $this->option('objects'))));
        $unknown = array_diff($objects, array_keys(self::ENTITIES));

        if ($unknown !== []) {
            $this->error('Unsupported object: ' . implode(', ', $unknown));

            return self::FAILURE;
        }

        $failed = false;

        foreach ($objects as $object) {
            try {
                $run = new StartIntegrationImportAction(
                    $integrationCompany,
                    $company->user,
                    self::ENTITIES[$object],
                )->execute();
                $this->line("Queued the {$object} import (run {$run->getId()}) for company {$company->name}");
            } catch (ValidationException $e) {
                $failed = true;
                $this->error("{$object}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
