<?php

declare(strict_types=1);

namespace App\Console\Commands\AccessControl;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateRoleAction;
use Kanvas\Apps\Models\Apps;

class CreateRoleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-role {name} {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command description';

    public function handle(): void
    {
        $roleName = $this->argument('name');
        $appId = $this->argument('app_id') ?? Apps::first()->getId();
        $createRole = new CreateRoleAction(
            $roleName,
            $roleName,
            Apps::getById($appId)
        );
        $createRole->execute();

        $this->info('Role ' . $roleName . ' created successfully.');
    }
}
