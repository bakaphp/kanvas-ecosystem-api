<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Bouncer;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Services\CompanyManagerService;
use Tests\TestCase;

final class CompanyManagerServiceTest extends TestCase
{
    private bool $assignedManagerRole = false;

    public function testAnAppWithoutTheRoleHasNoManagersInsteadOfThrowing(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $managers = new CompanyManagerService($company, app(Apps::class))
            ->getManagersByRole('ThisRoleWasNeverBootstrapped');

        $this->assertTrue($managers->isEmpty());
    }

    public function testTheRoleHoldersAreTheManagers(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, []);
        $this->assignManagerRole();

        $managers = new CompanyManagerService($company, $app)->getManagers();

        $this->assertTrue($managers->contains('id', $user->getId()));
    }

    public function testTheLegacyCompanyManagerSettingIsStillHonored(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, [$user->getId()]);

        $managers = new CompanyManagerService($company, app(Apps::class))
            ->getManagers('ThisRoleWasNeverBootstrapped');

        $this->assertTrue($managers->contains('id', $user->getId()));
    }

    public function testAManagerListedInBothSourcesIsReturnedOnce(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, [$user->getId()]);
        $this->assignManagerRole();

        $managers = new CompanyManagerService($company, app(Apps::class))->getManagers();

        $this->assertCount(1, $managers->where('id', $user->getId()));
    }

    public function testAnEmptyLegacySettingIsNotAManager(): void
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $company->set(CompanyManagerService::LEGACY_MANAGERS_SETTING, []);

        $managers = new CompanyManagerService($company, app(Apps::class))->getLegacyManagers();

        $this->assertTrue($managers->isEmpty());
    }

    /**
     * The test user is cached for the whole phpunit run, so a role assigned here would still be on
     * them in every later test of every later suite.
     */
    protected function tearDown(): void
    {
        if ($this->assignedManagerRole) {
            Bouncer::scope()->to(RolesEnums::getScope(app(Apps::class)));
            Bouncer::retract(CompanyManagerService::NOTIFICATION_MANAGER_ROLE)->from(auth()->user());
        }

        parent::tearDown();
    }

    private function assignManagerRole(): void
    {
        Bouncer::scope()->to(RolesEnums::getScope(app(Apps::class)));
        Bouncer::assign(CompanyManagerService::NOTIFICATION_MANAGER_ROLE)->to(auth()->user());

        $this->assignedManagerRole = true;
    }
}
