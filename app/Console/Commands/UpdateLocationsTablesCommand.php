<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Locations\Actions\UpdateAllLocationsAction;
use Kanvas\Locations\Actions\UpdateCitiesAction;
use Kanvas\Locations\Actions\UpdateCountriesAction;
use Kanvas\Locations\Actions\UpdateStatesAction;

class UpdateLocationsTablesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:update-locations-tables {app_uuid} {tableName?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command description';

    public function handle(): void
    {
        $appUid = $this->argument('app_uuid');
        $app = Apps::getByUuid($appUid);
        $table = $this->argument('tableName') ?? null;

        $updateLocations = match ($table) {
            'countries' => (new UpdateCountriesAction($app))->$countries->execute(),
            'states' => (new UpdateStatesAction($app))->$countries->execute(),
            'cities' => (new UpdateCitiesAction($app))->$countries->execute(),
            null => call_user_func(function () {
                $countries = new UpdateCountriesAction($app);
                $countries->execute();

                $states = new UpdateStatesAction($app);
                $states->execute();

                $cities = new UpdateCitiesAction($app);
                $cities->execute();
            }),
        };

        $this->info('Tables updated successfully.');
    }
}
