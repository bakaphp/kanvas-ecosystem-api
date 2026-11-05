<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum;
use Kanvas\Guild\Customers\Classification\Support\DefaultPeopleTaxonomy;

/**
 * Turns nothing on: assigning a taxonomy starts no LLM spend until people_classification_enabled is set.
 */
class AssignPeopleTaxonomyCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:people-classification-assign-taxonomy
                            {app_id : App to assign the default taxonomy to}
                            {--company_id= : Assign to this company only, otherwise the whole app}
                            {--force : Replace a taxonomy already set at this level, which reclassifies its people}';

    protected $description = 'Copy the default people classification taxonomy onto an app or company';

    public function handle(): int
    {
        /** @var Apps $app */
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $target = $app;

        if ($this->option('company_id') !== null) {
            $target = Companies::getById((int) $this->option('company_id'));

            try {
                CompaniesRepository::hasAccessToThisApp($target, $app);
            } catch (ModelNotFoundException) {
                $this->error('This company does not belong to the app.');

                return self::FAILURE;
            }
        }

        $key = PeopleClassificationConfigurationEnum::TAXONOMY->value;

        if ($target->get($key) !== null && ! $this->option('force')) {
            $this->error('A taxonomy is already set here. Use --force to replace it.');

            return self::FAILURE;
        }

        $target->set($key, DefaultPeopleTaxonomy::VALUE);
        $this->info('Default taxonomy assigned. Set people_classification_enabled to turn classification on.');

        return self::SUCCESS;
    }
}
