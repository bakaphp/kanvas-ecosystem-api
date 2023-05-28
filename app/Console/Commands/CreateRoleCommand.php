<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Kanvas\AccessControlList\Actions\CreateRoleAction;

class CreateRoleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:create-role {name}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Command description';

    public function handle(): void
    {
        $roleName = $this->argument('name');
        $createRole = new CreateRoleAction(
            $roleName,
            $roleName
        );
        $createRole->execute();

        $this->info('Role ' . $roleName . ' created successfully.');
    }
}
