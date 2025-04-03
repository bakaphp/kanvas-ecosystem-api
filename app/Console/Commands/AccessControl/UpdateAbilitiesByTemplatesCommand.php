<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateRolesByTemplates;
use Kanvas\Apps\Models\Apps;

class UpdateAbilitiesByTemplatesCommand extends Command
{
    protected $signature = 'kanvas:update-abilities-templates {app?}';

    protected $description = 'Command description';
    public function handle()
    {
        if ($key = $this->argument('app')) {
            $apps = Apps::where('key', $key)->get();
        } else {
            $apps = Apps::all();
        }
        foreach ($apps as $app) {
            (new CreateRolesByTemplates($app))->execute();
        }
    }
}
