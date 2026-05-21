<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Companies;

use Faker\Factory as FakerFactory;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Actions\CreateCompaniesAction;
use Kanvas\Companies\DataTransferObject\Company;
use Kanvas\Companies\Observers\CompaniesObserver;
use Kanvas\Enums\AppEnums;
use Kanvas\Enums\StateEnums;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Users\Models\UserRoles;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use ReflectionMethod;
use Tests\TestCase;

final class CompanyDefaultSetupTest extends TestCase
{
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
     * CompaniesObserver::setupDefaultIntegration wires the company to the
     * internal integration. It needs a default region to exist — which a fresh
     * company has not yet — so we provision the inventory defaults first, then
     * exercise the (private) setup directly the way the observer does.
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

        $setupDefaultIntegration = new ReflectionMethod(
            CompaniesObserver::class,
            'setupDefaultIntegration'
        );
        $setupDefaultIntegration->invoke(new CompaniesObserver(), $company, $app);

        $internal = Integrations::getByName(IntegrationsEnum::INTERNAL->value);
        $this->assertTrue(
            $company->integrations()
                ->where('integrations_id', $internal->getId())
                ->exists(),
            'The internal integration should be linked to the company.'
        );
    }
}
