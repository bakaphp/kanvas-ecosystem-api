<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\Movipass;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Console\Command;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Enums\MovipassRolesEnum;

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
        // parking_manager runs a parking owner's operation end to end; parking_operator is the
        // booth: sessions, plate/vehicle fixes and payments on tickets, nothing that changes
        // money totals, reports or the company itself.
        $abilities = [
            'list-orders' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::AGENT,
                MovipassRolesEnum::TRUCK_DRIVER,
                MovipassRolesEnum::PARQUEAT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'view-order' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::AGENT,
                MovipassRolesEnum::PARQUEAT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'update-orders' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::AGENT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'update-vouchers' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
            ],
            'download-orders' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::TRUCK_DRIVER,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'order-reports' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'cancel-orders' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'configure-company' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'list-paso-rapido' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::RDVIAL_CONSULTANT,
            ],
            'manage-paso-rapido' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
            ],
            'recharge-bulk' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
            ],
            'view-corporate-history' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
            ],
            'wallet-view' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::VIEWER,
            ],
            'wallet-recharge' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::FINANCE,
                MovipassRolesEnum::OPERATIONS,
            ],
            'wallet-refund' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::FINANCE,
            ],
            'wallet-export' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::FINANCE,
            ],
            'wallet-configure' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
            ],
            'correct-plate' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARQUEAT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'correct-vehicle-data' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARQUEAT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'adjust-amount' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'mark-duplicate' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARKING_MANAGER,
            ],
            'add-observations' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'associate-payment' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'relocate' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
                MovipassRolesEnum::OPERATIONS,
                MovipassRolesEnum::PARQUEAT,
                MovipassRolesEnum::PARKING_MANAGER,
                MovipassRolesEnum::PARKING_OPERATOR,
            ],
            'admin-reverse-transition' => [
                RolesEnums::OWNER,
                RolesEnums::ADMIN,
            ],
            'insurance-client' => [
                RolesEnums::INSURANCE_CLIENT,
            ],
        ];

        $scope = RolesEnums::getScope($app);
        Bouncer::scope()->to($scope);

        // disallow() below throws on a role that does not exist yet, which is every new enum
        // case on a fresh app — make them all exist before touching a single grant.
        foreach (MovipassRolesEnum::cases() as $movipassRole) {
            Bouncer::role()->firstOrCreate(
                ['name' => $movipassRole->value, 'scope' => $scope],
                ['title' => $movipassRole->value],
            );
        }

        foreach ($abilities as $ability => $roles) {
            foreach ($roles as $roleName) {
                Bouncer::allow($roleName->value)->to($ability);
            }

            // Bouncer::allow is additive-only, so a role dropped from an ability keeps the grant unless revoked here
            foreach (MovipassRolesEnum::cases() as $movipassRole) {
                if (! in_array($movipassRole, $roles, true)) {
                    Bouncer::disallow($movipassRole->value)->to($ability);
                }
            }
        }

        Bouncer::refresh();
    }
}
