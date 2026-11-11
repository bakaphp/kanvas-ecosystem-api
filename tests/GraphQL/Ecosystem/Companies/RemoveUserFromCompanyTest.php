<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Companies;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Users\Actions\ReassignDefaultCompanyAction;
use Kanvas\Users\Actions\SwitchCompanyBranchAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class RemoveUserFromCompanyTest extends TestCase
{
    public function testRemovingAUserFromOneOfTwoCompaniesKeepsThemActiveOnTheOther(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();
        $ownCompany = $user->getCurrentCompany();
        $sharedCompany = auth()->user()->getCurrentCompany();
        $this->addToCompany($sharedCompany, $user);
        new SwitchCompanyBranchAction($user, $sharedCompany->defaultBranch()->firstOrFail()->getId())->execute();

        $this->graphQL(
            '
            mutation removeUserFromCompany($id: ID!, $user_id: ID!) {
                removeUserFromCompany(id: $id, user_id: $user_id)
            }',
            [
                'id' => $sharedCompany->getId(),
                'user_id' => $user->getId(),
            ]
        )->assertSuccessful()->assertJsonPath('data.removeUserFromCompany', true);

        $user = Users::getById($user->getId());
        $this->assertTrue($user->getAppProfile($app)->isActive());
        $this->assertSame($ownCompany->getId(), (int) $user->default_company);
        $this->assertSame($ownCompany->getId(), $user->currentCompanyId());
    }

    public function testRemovingAUserFromTheirLastCompanyLeavesNothingToReassign(): void
    {
        $user = $this->createUser();

        $reassigned = new ReassignDefaultCompanyAction(
            $user,
            app(Apps::class),
            $user->getCurrentCompany()
        )->execute();

        $this->assertFalse($reassigned);
    }

    public function testAdminCanSwitchAUsersDefaultCompanyBranch(): void
    {
        $user = $this->createUser();
        $sharedCompany = auth()->user()->getCurrentCompany();
        $this->addToCompany($sharedCompany, $user);
        $branch = $sharedCompany->defaultBranch()->firstOrFail();

        $this->graphQL(
            '
            mutation appSwitchUserCompanyBranch($user_id: ID!, $company_branch_id: Int!) {
                appSwitchUserCompanyBranch(user_id: $user_id, company_branch_id: $company_branch_id)
            }',
            [
                'user_id' => $user->getId(),
                'company_branch_id' => $branch->getId(),
            ],
            [],
            $this->appKeyHeader()
        )->assertSuccessful()->assertJsonPath('data.appSwitchUserCompanyBranch', true);

        $user = Users::getById($user->getId());
        $this->assertSame($sharedCompany->getId(), (int) $user->default_company);
        $this->assertSame($branch->getId(), (int) $user->default_company_branch);
        $this->assertSame($sharedCompany->getId(), $user->currentCompanyId());
    }

    public function testAdminCannotSwitchAUserToABranchTheyDoNotBelongTo(): void
    {
        $user = $this->createUser();
        $branch = auth()->user()->getCurrentCompany()->defaultBranch()->firstOrFail();

        $response = $this->graphQL(
            '
            mutation appSwitchUserCompanyBranch($user_id: ID!, $company_branch_id: Int!) {
                appSwitchUserCompanyBranch(user_id: $user_id, company_branch_id: $company_branch_id)
            }',
            [
                'user_id' => $user->getId(),
                'company_branch_id' => $branch->getId(),
            ],
            [],
            $this->appKeyHeader()
        );

        $this->assertNotEmpty($response->json('errors'));
        $this->assertNotSame($branch->companies_id, Users::getById($user->getId())->currentCompanyId());
    }

    public function testAdminDeactivateAndActivateTargetTheProfileLoginChecks(): void
    {
        $app = app(Apps::class);
        $user = $this->createUser();
        $this->addToCompany(auth()->user()->getCurrentCompany(), $user);

        $this->graphQL(
            'mutation appDeActiveUser($user_id: ID!) { appDeActiveUser(user_id: $user_id) }',
            ['user_id' => $user->getId()],
            [],
            $this->appKeyHeader()
        )->assertSuccessful()->assertJsonPath('data.appDeActiveUser', true);

        $this->assertFalse($user->getAppProfile($app)->isActive());

        $this->graphQL(
            'mutation appActivateUser($user_id: ID!) { appActivateUser(user_id: $user_id) }',
            ['user_id' => $user->getId()],
            [],
            $this->appKeyHeader()
        )->assertSuccessful()->assertJsonPath('data.appActivateUser', true);

        $this->assertTrue($user->getAppProfile($app)->isActive());
    }

    private function addToCompany(Companies $company, Users $user): void
    {
        $this->graphQL(
            '
            mutation addUserToCompany($id: ID!, $user_id: ID!) {
                addUserToCompany(id: $id, user_id: $user_id)
            }',
            [
                'id' => $company->getId(),
                'user_id' => $user->getId(),
            ]
        )->assertSuccessful()->assertJsonMissingPath('errors');
    }

    private function appKeyHeader(): array
    {
        return [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => app(Apps::class)->keys()->first()->client_secret_id,
        ];
    }
}
