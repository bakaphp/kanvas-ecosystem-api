<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Kanvas\Users\Models\UsersAssociatedCompanies;
use Tests\TestCase;

class RegisterExistingUserAssignsGlobalCompanyTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        app(Apps::class)->del(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        parent::tearDown();
    }

    public function testRegisteringExistingUserNotInAppAssignsConfiguredGlobalCompany(): void
    {
        Bus::fake();

        $app = app(Apps::class);

        $globalCompanyOwner = $this->createUser();
        $globalCompany = $globalCompanyOwner->getCurrentCompany();
        $globalBranch = $globalCompany->branch()->firstOrFail();

        $app->set(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue(), (string) $globalBranch->getId());

        $existingUser = Users::factory()->create([
            'default_company' => 0,
        ]);

        $this->assertFalse(
            UsersAssociatedApps::where('users_id', $existingUser->getId())
                ->where('apps_id', $app->getId())
                ->exists(),
            'Precondition failed: user should not already belong to the current app'
        );

        $data = RegisterInput::fromArray(
            [
                'email' => $existingUser->email,
                'password' => fake()->password(9),
                'firstname' => $existingUser->firstname,
                'lastname' => $existingUser->lastname,
            ],
            $globalBranch
        );

        $action = new RegisterUsersAction($data, $app);
        $action->disableWorkflow();
        $registeredUser = $action->execute();

        $this->assertSame($existingUser->getId(), $registeredUser->getId());

        $registeredUser->refresh();

        $this->assertSame($globalCompany->getId(), (int) $registeredUser->default_company);
        $this->assertSame($globalBranch->getId(), (int) $registeredUser->default_company_branch);

        $this->assertTrue(
            UsersAssociatedCompanies::where('users_id', $registeredUser->getId())
                ->where('companies_id', $globalCompany->getId())
                ->where('companies_branches_id', $globalBranch->getId())
                ->exists(),
            'Expected the existing user to be assigned to the configured global company branch'
        );

        $this->assertTrue(
            UsersAssociatedApps::where('users_id', $registeredUser->getId())
                ->where('apps_id', $app->getId())
                ->where('companies_id', $globalCompany->getId())
                ->exists(),
            'Expected the existing user to be registered in this app under the configured global company'
        );

        $this->assertFalse(
            UsersAssociatedCompanies::where('users_id', $registeredUser->getId())
                ->where('companies_id', '!=', $globalCompany->getId())
                ->exists(),
            'A brand new company should NOT have been created for the existing user'
        );
    }

    public function testRegisteringExistingUserNotInAppWithoutGlobalCompanySettingCreatesNewCompany(): void
    {
        Bus::fake();

        $app = app(Apps::class);
        $app->del(AppSettingsEnums::GLOBAL_USER_REGISTRATION_ASSIGN_GLOBAL_COMPANY->getValue());

        $existingUser = Users::factory()->create([
            'default_company' => 0,
        ]);

        $data = RegisterInput::fromArray([
            'email' => $existingUser->email,
            'password' => fake()->password(9),
            'firstname' => $existingUser->firstname,
            'lastname' => $existingUser->lastname,
        ]);

        $action = new RegisterUsersAction($data, $app);
        $action->disableWorkflow();
        $registeredUser = $action->execute();

        $registeredUser->refresh();

        $this->assertNotEquals(0, $registeredUser->default_company);

        $this->assertTrue(
            UsersAssociatedCompanies::where('users_id', $registeredUser->getId())
                ->where('companies_id', $registeredUser->default_company)
                ->exists(),
            'Expected a new company to be created and assigned to the existing user'
        );
    }
}
