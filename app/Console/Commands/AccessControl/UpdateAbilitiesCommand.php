<?php

namespace App\Console\Commands\AccessControl;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateAbilitiesByModule;
use Kanvas\Apps\Models\Apps;

class UpdateAbilitiesCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:update-abilities {app?}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($key = $this->argument('app')) {
            $apps = Apps::where('key', $key)->get();
        } else {
            $apps = Apps::all();
        }
        foreach ($apps as $app) {
            (new CreateAbilitiesByModule($app))->execute();
        }
    }
}
