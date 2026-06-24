<?php

declare(strict_types=1);

namespace Tests\Connectors\SuperCarros;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SuperCarros\Actions\SuperCarrosImportAllCompaniesAction;
use Kanvas\Connectors\SuperCarros\Client;
use Kanvas\Connectors\SuperCarros\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Tests\TestCase;

final class SuperCarrosPerCompanyKeyTest extends TestCase
{
    protected Apps $apps;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        // base_url is the shared endpoint; the leaky app-level access key simulates the
        // legacy mistake we are fixing and must be ignored by the client.
        $this->apps->set(ConfigurationEnum::BASE_URL->value, 'https://api.supercarros.test');
        $this->apps->set(ConfigurationEnum::ACCESS_KEY->value, 'app-level-leaky-key');
    }

    public function testClientReadsAccessKeyFromCompanyNotApp(): void
    {
        $company = $this->makeCompany();
        $company->set(ConfigurationEnum::ACCESS_KEY->value, 'company-own-key');
        $company->set(ConfigurationEnum::CUSTOMER_ID->value, '555');

        // Construction validates config presence without any network call; it succeeds
        // only because the access key is resolved from the company.
        $client = new Client($this->apps, $company);

        $this->assertInstanceOf(Client::class, $client);
    }

    public function testClientFailsWhenCompanyHasNoOwnKeyEvenIfAppHasOne(): void
    {
        // Customer id is set but the company has no access key of its own. The app-level
        // key must NOT satisfy this — otherwise this company would pull another rooftop.
        $company = $this->makeCompany();
        $company->set(ConfigurationEnum::CUSTOMER_ID->value, '777');

        $this->expectException(ValidationException::class);

        new Client($this->apps, $company);
    }

    public function testGetConfiguredCompaniesReturnsOnlyCompaniesWithCustomerId(): void
    {
        $configured = $this->makeCompany();
        $configured->set(ConfigurationEnum::ACCESS_KEY->value, 'key-abc');
        $configured->set(ConfigurationEnum::CUSTOMER_ID->value, '123');

        $unconfigured = $this->makeCompany();

        $companyIds = SuperCarrosImportAllCompaniesAction::getConfiguredCompanies($this->apps)
            ->pluck('id')
            ->all();

        $this->assertContains($configured->id, $companyIds);
        $this->assertNotContains($unconfigured->id, $companyIds);
    }

    private function makeCompany(): Companies
    {
        $company = Companies::factory()->create([
            'users_id' => auth()->id(),
            'is_active' => 1,
        ]);
        $company->associateApp($this->apps);

        return $company;
    }
}
