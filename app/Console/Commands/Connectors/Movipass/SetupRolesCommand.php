<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;
use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;

class SetupRolesCommand extends Command
{
    use KanvasJobsTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'kanvas:movipass-setup-roles {app_id?}';

    /**
     * The console command description.
     *
     * @var string|null
     */
    protected $description = 'Setup roles for movipass orders';

    public function handle(): void
    {
        $appsId = $this->argument('app_id');
        $app = Apps::getById($appsId);
        $this->setupRoles($app);
       

        $this->info('Roles setup successfully');
    }

    protected function setupRoles(AppInterface $app): void
    {
        $abilities = [
            "list-orders" => [
                MovipassRolesEnum::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::AGENT,
            ],
            "update-orders" => [
                MovipassRolesEnum::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::AGENT,
            ],
            "download-orders" => [
                MovipassRolesEnum::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
            ],
            "order-reports" => [
                MovipassRolesEnum::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
            ]
        ];

        Bouncer::scope()->to(RolesEnums::getScope($app));
        foreach ($abilities as $ability => $roles) {
            foreach ($roles as $roleName) {
                Bouncer::allow($roleName->value)->to($ability);
            }
        }
    }
}
