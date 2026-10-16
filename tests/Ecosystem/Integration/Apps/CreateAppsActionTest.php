<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Apps;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Models\Role;
use Kanvas\Apps\Actions\CreateAppsAction;
use Kanvas\Apps\DataTransferObject\AppInput;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UserCompanyApps;
use Tests\TestCase;

final class CreateAppsActionTest extends TestCase
{
    /**
     * Test Create Apps Action.
     */
    public function testCreateAppsAction(): void
    {
        $data = [
            'url' => 'example.com',
            'is_actived' => '1',
            'ecosystem_auth' => '1',
            'payments_active' => '1',
            'is_public' => '1',
            'domain_based' => '1',
            'name' => 'CRM app 2',
            'description' => 'Kanvas Application',
            'domain' => 'example.com',
        ];
        //Create new AppInput
        $dtoData = AppInput::from($data);

        $app = new CreateAppsAction($dtoData, auth()->user());

        $this->assertInstanceOf(
            Apps::class,
            $app->execute()
        );
    }

    public function testCreateAppsActionCreatesDefaultRoles(): void
    {
        $app = new CreateAppsAction(
            AppInput::from([
                'url' => 'default-roles.com',
                'is_actived' => '1',
                'ecosystem_auth' => '1',
                'payments_active' => '1',
                'is_public' => '1',
                'domain_based' => '1',
                'name' => 'Default Roles app ' . fake()->unique()->uuid(),
                'description' => 'Kanvas Application',
                'domain' => 'default-roles.com',
            ]),
            auth()->user()
        )->execute();

        $scope = RolesEnums::getScope($app);

        foreach ([
            RolesEnums::OWNER,
            RolesEnums::ADMIN,
            RolesEnums::USER,
            RolesEnums::MANAGER,
            RolesEnums::DEVELOPER,
            RolesEnums::INVENTORY_MANAGER,
            RolesEnums::ENGAGEMENT_USAGE_REPORT,
        ] as $role) {
            $this->assertTrue(
                Role::where('name', $role->value)->where('scope', $scope)->exists(),
                'Missing default role ' . $role->value . ' for scope ' . $scope
            );
        }
    }

    public function testCreateAppsActionAutoRegistersTheCreatingUserWithADefaultCompany(): void
    {
        $user = auth()->user();
        $appName = 'Owner Company app ' . fake()->unique()->uuid();

        $app = new CreateAppsAction(
            AppInput::from([
                'url' => 'owner-company.com',
                'is_actived' => '1',
                'ecosystem_auth' => '1',
                'payments_active' => '1',
                'is_public' => '1',
                'domain_based' => '1',
                'name' => $appName,
                'description' => 'Kanvas Application',
                'domain' => 'owner-company.com',
            ]),
            $user
        )->execute();

        $company = Companies::where('users_id', $user->getId())
            ->where('name', $appName)
            ->latest('id')
            ->first();

        $this->assertNotNull($company, 'No default company named after the app was created for the app-creating user.');
        $this->assertTrue(
            UserCompanyApps::where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->exists(),
            'The default company was not associated to the newly created app.'
        );
    }
}
