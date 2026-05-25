<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateAbilitiesByModule;
use Kanvas\Apps\Models\Apps;

class UpdateAbilitiesCommand extends Command
{
    use KanvasJobsTrait;

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

        /** @var Apps $app */
        foreach ($apps as $app) {
            // Rebind container Apps + Bouncer scope so any code inside
            // CreateAbilitiesByModule that resolves `app(Apps::class)`
            // sees this iteration's app, not the one bound at boot.
            $this->overwriteAppService($app);

            new CreateAbilitiesByModule($app)->execute();
        }
    }
}
