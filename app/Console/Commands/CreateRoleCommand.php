<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;

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
        $role = Bouncer::role()->firstOrCreate([
            'name' => $this->argument('name'),
            'title' => $this->argument('name'),
            'scope' => RolesEnums::getKey(app(Apps::class), null),
        ]);
    }
}
