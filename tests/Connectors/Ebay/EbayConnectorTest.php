<?php

declare(strict_types=1);

namespace Tests\Connectors\Ebay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Ebay\Activities\PublishProductToEbayActivity;
use Kanvas\Connectors\Ebay\Client;
use Kanvas\Connectors\Ebay\Enums\ConfigurationEnum;
use Kanvas\Connectors\Ebay\Enums\CustomFieldEnum;
use Kanvas\Connectors\Ebay\Handlers\EbayHandler;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseUnit;

#[CoversClass(EbayHandler::class)]
#[CoversClass(Client::class)]
#[CoversClass(PublishProductToEbayActivity::class)]
final class EbayConnectorTest extends TestCaseUnit
{
    public function testHandlerValidatesAndPersistsCompanyConfiguration(): void
    {
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $region = Mockery::mock(Regions::class);
        $data = [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'dev_id' => 'test-dev-id',
            'redirect_uri' => 'https://example.test/callback',
            'environment' => 'sandbox',
            'ru_name' => 'test-ru-name',
        ];

        $company->shouldReceive('get')->with(CustomFieldEnum::EBAY_REFRESH_TOKEN->value)->andReturn(null);
        $company->shouldReceive('set')->times(6)->andReturn(true);
        $client = $this->createMock(Client::class);
        $client->expects($this->once())->method('validateCredentials')
            ->with('test-client-id', 'test-client-secret', null, 'sandbox')
            ->willReturn(true);
        $handler = new class (
            $app,
            $company,
            $region,
            $data,
            $client,
        ) extends EbayHandler {
            public function __construct(
                Apps $app,
                Companies $company,
                Regions $region,
                array $data,
                private Client $fakeClient,
            ) {
                parent::__construct($app, $company, $region, $data);
            }

            #[Override]
            protected function client(): Client
            {
                return $this->fakeClient;
            }
        };

        $this->assertTrue($handler->setup());
    }

    public function testTeardownDeletesEverySettingSetupCanPersist(): void
    {
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $deletedKeys = [];
        $company->shouldReceive('del')->times(11)->andReturnUsing(static function (string $key) use (&$deletedKeys): bool {
            $deletedKeys[] = $key;

            return true;
        });
        $handler = new EbayHandler(
            $app,
            $company,
            Mockery::mock(Regions::class),
            [],
        );

        $handler->teardown();
        $expectedKeys = array_map(
            static fn (ConfigurationEnum|CustomFieldEnum $key): string => $key->value,
            [
                ConfigurationEnum::EBAY_CLIENT_ID,
                ConfigurationEnum::EBAY_CLIENT_SECRET,
                ConfigurationEnum::EBAY_DEV_ID,
                ConfigurationEnum::EBAY_REDIRECT_URI,
                ConfigurationEnum::EBAY_ENVIRONMENT,
                ConfigurationEnum::EBAY_RU_NAME,
                CustomFieldEnum::EBAY_ACCESS_TOKEN,
                CustomFieldEnum::EBAY_REFRESH_TOKEN,
                CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT,
                CustomFieldEnum::EBAY_REFRESH_TOKEN_EXPIRES_AT,
                CustomFieldEnum::EBAY_SELLER_USERNAME,
            ]
        );
        sort($deletedKeys);
        sort($expectedKeys);
        $this->assertSame($expectedKeys, $deletedKeys);
    }

    public function testExpiredSellerAccessTokenIsRefreshedAndSaved(): void
    {
        $settings = [
            ConfigurationEnum::EBAY_CLIENT_ID->value => 'test-client-id',
            ConfigurationEnum::EBAY_CLIENT_SECRET->value => 'test-client-secret',
            ConfigurationEnum::EBAY_ENVIRONMENT->value => 'sandbox',
            CustomFieldEnum::EBAY_ACCESS_TOKEN->value => 'expired-token',
            CustomFieldEnum::EBAY_TOKEN_EXPIRES_AT->value => time() - 10,
            CustomFieldEnum::EBAY_REFRESH_TOKEN->value => 'test-refresh-token',
        ];
        $company = Mockery::mock(CompanyInterface::class);
        $company->shouldReceive('get')->andReturnUsing(static function (string $key, mixed $default = null) use (&$settings): mixed {
            return $settings[$key] ?? $default;
        });
        $company->shouldReceive('set')->twice()->andReturnUsing(static function (string $key, mixed $value) use (&$settings): bool {
            $settings[$key] = $value;

            return true;
        });
        $app = Mockery::mock(AppInterface::class);
        $app->shouldReceive('get')->andReturn(null);
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['access_token' => 'fresh-token', 'expires_in' => 3600])),
        ]));
        $stack->push(Middleware::history($history));
        $client = new Client($app, $company, new GuzzleClient(['handler' => $stack]));

        $this->assertSame('fresh-token', $client->resolveAccessToken());
        $this->assertSame('fresh-token', $settings[CustomFieldEnum::EBAY_ACCESS_TOKEN->value]);
        $this->assertSame('https://auth.sandbox.ebay.com/oauth2/token', (string) $history[0]['request']->getUri());
    }

    public function testWorkflowActivityExecutesPublishAndPassesRetryParams(): void
    {
        $activity = new class (
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            [],
        ) extends PublishProductToEbayActivity {
            public array $receivedParams = [];

            public ?IntegrationsEnum $receivedIntegration = null;

            #[Override]
            public function overwriteAppService(AppInterface $app): void
            {
            }

            #[Override]
            public function executeIntegration(
                Model $entity,
                AppInterface $app,
                IntegrationsEnum $integration,
                callable $integrationOperation,
                array $additionalParams = [],
                ?Regions $region = null,
                ?Companies $company = null,
                bool $throwException = false,
            ): array {
                $this->receivedParams = $additionalParams;
                $this->receivedIntegration = $integration;

                return $integrationOperation(
                    $entity,
                    $app,
                    null,
                    $additionalParams,
                );
            }

            #[Override]
            protected function publish(Products|Variants $product, array $params): array
            {
                return ['listing_id' => '123456789', 'params' => $params];
            }
        };
        $app = Mockery::mock(Apps::class);
        $company = Mockery::mock(Companies::class);
        $product = new Products();
        $product->setRelation('company', $company);
        $params = ['category_id' => '123', 'fulfillment_policy_id' => 'policy-1'];

        $result = $activity->execute($product, $app, $params);

        $this->assertSame(IntegrationsEnum::EBAY, $activity->receivedIntegration);
        $this->assertSame($params, $activity->receivedParams);
        $this->assertSame(['listing_id' => '123456789', 'params' => $params], $result);
    }
}
