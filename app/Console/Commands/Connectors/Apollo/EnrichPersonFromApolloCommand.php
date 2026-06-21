<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Apollo;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Apollo\Actions\EnrichPeopleFromApolloAction;
use Kanvas\Guild\Customers\Models\People;

class EnrichPersonFromApolloCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:guild-apollo-enrich-person {app_id} {company_id} {people_id}';

    protected $description = 'Enrich a single person from Apollo directly, bypassing the workflow/activity and the recently-screened gating';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $company = Companies::getById((int) $this->argument('company_id'));

        /** @var People $people */
        $people = People::getByIdFromCompanyApp((int) $this->argument('people_id'), $company, $app);

        $this->line("Enriching {$people->firstname} {$people->lastname} (#{$people->getId()}) from Apollo...");

        $result = new EnrichPeopleFromApolloAction($people, $app)->execute();

        $this->line("[{$result['status']}] {$result['message']}");

        return $result['status'] === 'success' ? self::SUCCESS : self::FAILURE;
    }
}
