<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateAbilitiesByModule;
use Kanvas\Apps\Models\Apps;
use Silber\Bouncer\Database\Ability;

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
        \Bouncer::scope()->to('app_1_company_0');
        $company = Ability::leftJoin('permissions', 'permissions.ability_id', '=', 'abilities.id')
            ->where('permissions.entity_type', 'roles')
            ->select('abilities.*')
            ->get();
        dd($company);
    }
}
