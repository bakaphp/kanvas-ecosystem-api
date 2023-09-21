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
    protected $signature = 'kanvas:update-locations-tables {app_uuid} {tableNumber?}';

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
        $table = $this->argument('tableNumber') ?? null;

        switch ($table) {
            case '1':
                $countries = new UpdateCountriesAction();
                $countries->execute($app);
                break;
            case '2':
                $states = new UpdateStatesAction();
                $states->execute($app);
                break;
        
            case '3':
                $cities = new UpdateCitiesAction();
                $cities->execute($app);
                break;
            
            case null:
                $countries = new UpdateCountriesAction();
                $countries->execute($app);
        
                $states = new UpdateStatesAction();
                $states->execute($app);
        
                $cities = new UpdateCitiesAction();
                $cities->execute($app);
                break;
        }

        $this->info('Tables updated successfully.');
    }
}
