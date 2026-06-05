<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Faker\Factory as FakerFactory;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\ActionEngine\Support\Setup as ActionEngineSetup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\AppSettingsEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Users\Jobs\OnBoardingJob;
use Kanvas\Users\Models\UserRoles;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use ReflectionMethod;
use Tests\TestCase;

final class CompanyDefaultSetupTest extends TestCase
{
    protected function tearDown(): void
    {
        $app = app(Apps::class);
        $app->del(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP->getValue());
        $app->del(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY->getValue());

        parent::tearDown();
    }

    /**
     * Creating a company runs CompaniesObserver::created, which provisions the
     * default setup: uuid, default branch, company group, owner associations,
     * and the default role. This test guards that whole bootstrap.
     */
    public function testCompanyDefaultSetupAfterCreation(): void
    {
        $faker = FakerFactory::create();
        $app = app(Apps::class);
        /** @var Users $owner */
        $owner = Users::factory(1)->create()->first();

        $company = new CreateCompaniesAction(
            Company::viaRequest(['name' => $faker->company], $owner)
        )->execute();

        $this->assertNotEmpty($company->uuid, 'A uuid should be generated on creating.');

        $defaultBranch = $company->defaultBranch()->first();
        $this->assertNotNull($defaultBranch, 'A default branch should be created for the company.');
        $this->assertSame(AppEnums::DEFAULT_NAME->getValue(), $defaultBranch->name);
        $this->assertSame(StateEnums::YES->getValue(), (int) $defaultBranch->is_default);

        $this->assertGreaterThan(
            0,
            $company->groups()->count(),
            'The company should be associated to at least one group.'
        );

        $this->assertTrue(
            $company->users()->where('users.id', $owner->getId())->exists(),
            'The owner should be associated to the company for the current app.'
        );

        $userRole = UserRoles::where('users_id', $owner->getId())
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->first();
        $this->assertNotNull($userRole, 'The owner should be assigned the default role.');
    }

    /**
     * OnBoardingJob::setupDefaultIntegration wires the company to the internal
     * integration. It needs a default region to exist — which a fresh company
     * has not yet — so the job runs it right after InventorySetup. Here we
     * provision the inventory defaults first, then exercise that (private)
     * setup directly the way the job does.
     */
    public function testDefaultInternalIntegrationIsSetUp(): void
    {
        $faker = FakerFactory::create();
        $app = app(Apps::class);
        /** @var Users $owner */
        $owner = Users::factory(1)->create()->first();

        $company = new CreateCompaniesAction(
            Company::viaRequest(['name' => $faker->company], $owner)
        )->execute();

        new Setup(app: $app, user: $owner, company: $company)->run();
        $this->assertNotNull(
            $company->defaultRegion()->first(),
            'Inventory setup should give the company a default region.'
        );

        $onBoardingJob = new OnBoardingJob(
            $owner,
            $company->defaultBranch()->firstOrFail(),
            $app
        );

        $setupDefaultIntegration = new ReflectionMethod(
            OnBoardingJob::class,
            'setupDefaultIntegration'
        );
        $setupDefaultIntegration->invoke($onBoardingJob, $company, $app);

        $internal = Integrations::getByName(IntegrationsEnum::INTERNAL->value);
        $this->assertTrue(
            $company->integrations()
                ->where('integrations_id', $internal->getId())
                ->exists(),
            'The internal integration should be linked to the company.'
        );
    }

    /**
     * Action Engine setup only runs during onboarding when the app opts in via
     * ONBOARDING_ACTION_ENGINE_SETUP. With the flag on and no source company, it
     * provisions the built-in default company actions.
     */
    public function testOnboardingRunsActionEngineSetupWhenConfigured(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $app->set(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP->getValue(), true);

        $company = $this->makeCompany();

        new OnBoardingJob(
            $user,
            $company->defaultBranch()->firstOrFail(),
            $app
        )->handle();

        $companyActions = CompanyAction::where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->get();
        $slugs = $companyActions->map(fn (CompanyAction $ca) => $ca->action->slug)->all();

        $this->assertCount(2, $companyActions, 'Action engine onboarding should create the 2 defaults.');
        $this->assertContains(ActionEnum::VIEW_PRODUCT->value, $slugs);
        $this->assertContains(ActionEnum::GET_DOCS->value, $slugs);
    }

    /**
     * Without the opt-in flag, onboarding must not touch the Action Engine.
     */
    public function testOnboardingSkipsActionEngineWhenNotConfigured(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $company = $this->makeCompany();

        new OnBoardingJob(
            $user,
            $company->defaultBranch()->firstOrFail(),
            $app
        )->handle();

        $this->assertSame(
            0,
            CompanyAction::where('companies_id', $company->getId())->count(),
            'Without the flag, onboarding should not create any company actions.'
        );
    }

    /**
     * When ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY holds a company id, the
     * onboarding setup clones that company's action config into the new company.
     */
    public function testOnboardingActionEngineClonesFromConfiguredCompany(): void
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        $source = $this->makeCompany();
        new ActionEngineSetup($app, $user, $source)->run();

        $sourceGetDocs = CompanyAction::where('companies_id', $source->getId())
            ->whereHas('action', fn ($q) => $q->where('slug', ActionEnum::GET_DOCS->value))
            ->where('is_deleted', 0)
            ->firstOrFail();
        $sourceGetDocs->name = 'Custom Docs Name';
        $sourceGetDocs->saveOrFail();

        $app->set(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP->getValue(), true);
        $app->set(AppSettingsEnums::ONBOARDING_ACTION_ENGINE_SETUP_FROM_COMPANY->getValue(), $source->getId());

        $target = $this->makeCompany();

        new OnBoardingJob(
            $user,
            $target->defaultBranch()->firstOrFail(),
            $app
        )->handle();

        $targetGetDocs = CompanyAction::where('companies_id', $target->getId())
            ->whereHas('action', fn ($q) => $q->where('slug', ActionEnum::GET_DOCS->value))
            ->where('is_deleted', 0)
            ->firstOrFail();

        $this->assertSame(
            'Custom Docs Name',
            $targetGetDocs->name,
            'Onboarding clone should copy the source company action config.'
        );
    }

    private function makeCompany(): Companies
    {
        $faker = FakerFactory::create();
        /** @var Users $user */
        $user = auth()->user();

        return new CreateCompaniesAction(
            Company::viaRequest(['name' => $faker->unique()->company], $user)
        )->execute();
    }
}
