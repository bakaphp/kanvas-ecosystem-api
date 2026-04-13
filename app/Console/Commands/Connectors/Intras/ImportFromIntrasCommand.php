<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Actions\FullImportFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullEventsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullFacilitatorsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullLeadsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullPlansFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullLookupDataFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullOrganizationsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullParticipantsFromIntrasAction;
use Kanvas\Connectors\Intras\Actions\PullRegistrationsFromIntrasAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Users\Models\Users;
use Throwable;

class ImportFromIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-import
                            {app_id : The application ID}
                            {company_id : Company ID}
                            {user_id : User ID}
                            {--entity=all : Entity to import (all, lookup-data, organizations, participants, facilitators, events, registrations, leads, plans)}
                            {--agency-id= : Filter by Intras agency ID (1=INTRAS, 2=SKILLS, 3=FRANKLINCOVEY, 4=SUMMIT)}
                            {--since= : Only import records updated since this datetime (Y-m-d H:i:s)}';

    protected $description = 'Import data from legacy Intras/SIPGO database into Kanvas';

    public function handle(): void
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));
        $user = Users::getById((int) $this->argument('user_id'));
        $entity = $this->option('entity');
        $since = $this->option('since');
        $agencyId = $this->option('agency-id') !== null ? (int) $this->option('agency-id') : null;

        $this->info("Starting Intras import for company: {$company->name}");
        $this->info("Entity: {$entity}");

        if ($agencyId !== null) {
            $this->info("Agency ID: {$agencyId}");
            $company->set(CustomFieldEnum::INTRAS_AGENCY_ID->value, $agencyId);
        }

        if ($since !== null) {
            $this->info("Since: {$since}");
        }

        try {
            if ($entity === 'all') {
                $results = new FullImportFromIntrasAction($app, $company, $user, $since, $agencyId)->execute();
                $this->displayResults($results);

                return;
            }

            $result = match ($entity) {
                'lookup-data' => ['lookup' => new PullLookupDataFromIntrasAction($app, $company, $user, $agencyId)->execute()],
                'organizations' => ['organizations' => new PullOrganizationsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'participants' => ['participants' => new PullParticipantsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'facilitators' => ['facilitators' => new PullFacilitatorsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'events' => ['events' => new PullEventsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'registrations' => ['registrations' => new PullRegistrationsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'leads' => ['leads' => new PullLeadsFromIntrasAction($app, $company, $user, $since, $agencyId)->execute()],
                'plans' => ['plans' => new PullPlansFromIntrasAction($app, $company, $user, $agencyId)->execute()],
                default => throw new InvalidArgumentException("Unknown entity: {$entity}"),
            };

            $this->displayResults($result);
        } catch (Throwable $e) {
            $this->error("Import failed: {$e->getMessage()}");
            $this->error($e->getTraceAsString());
        }
    }

    protected function displayResults(array $results): void
    {
        foreach ($results as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $subKey => $subValue) {
                    $this->info("  {$key}.{$subKey}: " . (string) $subValue);
                }
            } else {
                $this->info("  {$key}: " . (string) $value);
            }
        }

        $this->info('Import completed.');
    }
}
