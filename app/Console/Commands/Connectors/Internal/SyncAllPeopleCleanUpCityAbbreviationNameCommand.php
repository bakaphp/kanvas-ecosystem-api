<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Internal;

use Baka\Support\USCityAbbreviations;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;

class SyncAllPeopleCleanUpCityAbbreviationNameCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:internal-people-clean-city-name {app_id} {company_id} {total=150} {perPage=50}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Sync all people in company and clean up city name';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $perPage = (int) $this->argument('perPage');
        $total = (int) $this->argument('total');
        $this->overwriteAppService($app);
        $company = Companies::getById((int) $this->argument('company_id'));

        $this->line("Syncing people for company {$company->name} from app {$app->name}, total {$total}, per page {$perPage}");

        // Fetch people count for progress bar
        $peopleCount = People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->count();

        // Add a progress bar
        $this->output->progressStart($peopleCount);

        People::fromApp($app)
            ->fromCompany($company)
            ->notDeleted(0)
            ->orderBy('peoples.id', 'DESC')
            ->limit($total)
            ->chunk($perPage, function ($peoples) use ($app) {
                foreach ($peoples as $people) {
                    //get its address and clean up the city name
                    $address = $people->address;
                    foreach ($address as $add) {
                        if (empty($add->city)) {
                            continue;
                        }
                        $add->city = USCityAbbreviations::expand($add->city);
                        $add->save();
                    }

                    $this->output->progressAdvance(); // Advance the progress bar for each person
                }
            });

        $this->output->progressFinish(); // Finish the progress bar after all people are processed

        $this->line("All people for company {$company->name} from app {$app->name} synced");
    }
}
