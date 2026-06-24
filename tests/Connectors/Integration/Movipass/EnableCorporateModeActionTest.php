<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Movipass;

use Bouncer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\EnableCorporateModeAction;
use Kanvas\Connectors\Movipass\Jobs\MigrateCorporateUserVariantsJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Jobs\OnBoardingJob;
use Tests\TestCase;

final class EnableCorporateModeActionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('GITHUB_ACTIONS')) {
            $this->markTestSkipped('Movipass corporate workflow tests are skipped in CI');
        }
    }

    public function testApprovesValidUpgradeAndDispatchesMigrationJob(): void
    {
        Bus::fake();

        $user = Auth::user();
        $app = app(Apps::class);

        // Mirror a real GraphQL request: middleware leaves the Bouncer tenant scope on the
        // user's current company, not the app-global company_0 where roles live. This is the
        // exact condition that made the admin-role lookup throw "No query results for Role".
        Bouncer::scope()->to(RolesEnums::getScope($app, $user->getCurrentCompany()));

        $company = new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $this->validFields(),
        )->execute();

        $this->assertNotNull($company);
        $this->assertTrue((bool) $company->get('is_corporate'));
        $this->assertEquals('Empresa de Pruebas SRL', $company->get('legal_name'));
        $this->assertEquals('Empresa Pruebas', $company->get('commercial_name'));
        $this->assertEquals('131123456', $company->get('rnc'));

        $user->refresh();
        $this->assertTrue((bool) $user->get('is_corporate'));
        $this->assertEquals('Juan Pérez', $user->get('contact_name'));
        $this->assertEquals('Gerente', $user->get('contact_role'));

        Bus::assertDispatched(MigrateCorporateUserVariantsJob::class);
    }

    public function testSwitchesUserDefaultCompanyToTheNewCorporateCompany(): void
    {
        Bus::fake();

        $user = Auth::user();
        $app = app(Apps::class);

        Bouncer::scope()->to(RolesEnums::getScope($app, $user->getCurrentCompany()));

        $previousCompanyId = $user->getCurrentCompany()->getId();

        $company = new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $this->validFields(),
        )->execute();

        $branch = $company->branch()->firstOrFail();

        $user->refresh();
        $this->assertNotEquals($previousCompanyId, $company->getId());
        $this->assertEquals($company->getId(), $user->default_company);
        $this->assertEquals($branch->getId(), $user->default_company_branch);
    }

    public function testDispatchesOnboardingForTheCorporateCompany(): void
    {
        Bus::fake();

        $user = Auth::user();
        $app = app(Apps::class);

        Bouncer::scope()->to(RolesEnums::getScope($app, $user->getCurrentCompany()));

        $company = new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $this->validFields(),
        )->execute();

        // Region + warehouse are provisioned by OnBoardingJob (Inventory Setup), the same
        // path the user-registration/lead-accept flow runs.
        Bus::assertDispatched(
            OnBoardingJob::class,
            fn (OnBoardingJob $job) => $job->branch->companies_id === $company->getId(),
        );
    }

    public function testRejectsInvalidRnc(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('RNC must be 9 or 11 digits');

        new EnableCorporateModeAction(
            user: Auth::user(),
            app: app(Apps::class),
            fields: $this->validFields(['rnc' => '1234567']),
        )->execute();
    }

    public function testRejectsAlreadyCorporateUser(): void
    {
        Bus::fake();

        $user = Auth::user();
        $app = app(Apps::class);

        new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $this->validFields(),
        )->execute();

        $user->refresh();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('User is already corporate');

        new EnableCorporateModeAction(
            user: $user,
            app: $app,
            fields: $this->validFields(),
        )->execute();
    }

    private function validFields(array $overrides = []): array
    {
        return array_merge([
            'legal_name' => 'Empresa de Pruebas SRL',
            'commercial_name' => 'Empresa Pruebas',
            'rnc' => '131123456',
            'contact_name' => 'Juan Pérez',
            'contact_role' => 'Gerente',
            'contact_email' => fake()->unique()->safeEmail(),
            'contact_phone' => '8095551234',
        ], $overrides);
    }
}
