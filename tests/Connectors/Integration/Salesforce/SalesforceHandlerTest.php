<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Salesforce\Enums\ConfigurationEnum;
use Kanvas\Connectors\Salesforce\Handlers\SalesforceHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Regions\Models\Regions;
use Tests\Connectors\Traits\HasSalesforceConfiguration;
use Tests\TestCase;

final class SalesforceHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use HasSalesforceConfiguration;

    public function testSetupThrowsValidationExceptionWhenCredentialsAreMissing(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $handler = new SalesforceHandler($app, $company, $region, [
            'client_id' => '',
            'client_secret' => '',
            'refresh_token' => '',
        ]);

        $this->expectException(ValidationException::class);

        $handler->setup();
    }

    public function testSetupStoresCredentialsAndValidatesAgainstSalesforce(): void
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->fakeSalesforceOAuth();
        Http::fake([
            self::SALESFORCE_INSTANCE_URL . '/services/data/v60.0/sobjects/' => Http::response([
                'sobjects' => [['name' => 'Location__c', 'label' => 'Location']],
            ], 200),
        ]);

        $handler = new SalesforceHandler($app, $company, $region, [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'refresh_token' => 'test-refresh-token',
            'login_url' => self::SALESFORCE_LOGIN_URL,
        ]);

        $this->assertTrue($handler->setup());
        $this->assertSame('test-client-id', $company->get(ConfigurationEnum::CLIENT_ID->value));
        $this->assertSame('test-client-secret', $company->get(ConfigurationEnum::CLIENT_SECRET->value));
        $this->assertSame('test-refresh-token', $company->get(ConfigurationEnum::REFRESH_TOKEN->value));
        $this->assertSame(self::SALESFORCE_LOGIN_URL, $company->get(ConfigurationEnum::LOGIN_URL->value));
    }
}
