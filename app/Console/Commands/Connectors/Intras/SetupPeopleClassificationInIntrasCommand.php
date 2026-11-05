<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Intras;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Intras\Actions\SetupPeopleClassificationInIntrasAction;
use Kanvas\Connectors\Intras\Support\IntrasPeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Rules\Models\RuleType;

/**
 * Writes to the live SIPGO database. Turns nothing on: the enabled flag and the workflow rules stay
 * separate steps, so the backfill can be sampled first.
 */
class SetupPeopleClassificationInIntrasCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:intras-setup-people-classification
                            {app_id : The Intras app}
                            {--dry-run : Report what is missing and change nothing}';

    protected $description = 'Set the INTRAS taxonomy and create the two classification custom fields in SIPGO';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $dryRun = (bool) $this->option('dry-run');
        $missing = new SetupPeopleClassificationInIntrasAction($app, $dryRun)->execute();

        $this->info(sprintf(
            'SIPGO custom fields %s: %s',
            $dryRun ? 'to create' : 'created',
            $missing === [] ? 'none' : implode(', ', $missing),
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        $app->set(PeopleClassificationConfigurationEnum::TAXONOMY->value, IntrasPeopleTaxonomy::VALUE);
        RuleType::firstOrCreate(['name' => WorkflowEnum::PEOPLE_CLASSIFIED->value]);

        $this->info('Taxonomy set on the app.');

        return self::SUCCESS;
    }
}
