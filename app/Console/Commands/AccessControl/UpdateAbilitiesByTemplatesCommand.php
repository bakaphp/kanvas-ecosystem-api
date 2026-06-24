<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateRolesByTemplatesAction;
use Kanvas\Apps\Models\Apps;

class UpdateAbilitiesByTemplatesCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:update-abilities-templates {app?}';

    protected $description = 'Command description';
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
            // CreateRolesByTemplatesAction that resolves `app(Apps::class)`
            // sees this iteration's app, not the one bound at boot.
            $this->overwriteAppService($app);

            new CreateRolesByTemplatesAction($app)->execute();
        }
    }
}
