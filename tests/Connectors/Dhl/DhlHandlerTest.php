<?php

declare(strict_types=1);

namespace Tests\Connectors\Dhl;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Dhl\Client;
use Kanvas\Connectors\Dhl\Enums\ConfigurationEnum;
use Kanvas\Connectors\Dhl\Handlers\DhlHandler;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Capability\Services\ActiveIntegrationsService;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\Integrations;
use Tests\Connectors\Traits\HasDhlConfiguration;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class DhlHandlerTest extends TestCase
{
    use DatabaseTransactions;
    use HasDhlConfiguration;
    use InventoryCases;

    protected $connectionsToTransact = [null, 'workflow', 'inventory', 'ecosystem'];

    private Companies $company;
    private Regions $region;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $this->company = Companies::factory()->create();
        $this->region = $this->createDefaultRegion($this->company, app(Apps::class), $user);
    }

    public function testSetupStoresTheCredentialsEncryptedAndValidatesWithARatesCall(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response($this->dhlRatesResponse())]);

        $this->assertTrue($this->handler($this->setupData())->setup());

        $this->assertSame('apiUser', $this->company->get(ConfigurationEnum::USERNAME->value));
        $this->assertSame('apiSecret', $this->company->get(ConfigurationEnum::PASSWORD->value));
        $this->assertTrue($this->company->isSecret(ConfigurationEnum::PASSWORD->value));
        $this->assertSame('SD', $this->company->get(ConfigurationEnum::SIGNATURE_SERVICE->value));
        $this->assertSame('DO', $this->company->get(ConfigurationEnum::ORIGIN->value)['country_code']);

        Http::assertSent(fn (Request $request): bool => $request->url() === self::DHL_HOST . '/rates'
            && $request->hasHeader('x-version', ConfigurationEnum::API_VERSION)
            && $request->hasHeader('Authorization', 'Basic ' . base64_encode('apiUser:apiSecret'))
            && $request['valueAddedServices'][0]['serviceCode'] === 'SD');
    }

    public function testSetupReportsFailureWhenDhlRejectsTheCredentials(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response(['title' => 'Unauthorized', 'status' => 401], 401)]);

        $this->assertFalse($this->handler($this->setupData())->setup());
    }

    public function testSetupRefusesAnIncompleteOrigin(): void
    {
        $data = $this->setupData();
        unset($data['origin']['postal_code']);

        $this->expectException(ValidationException::class);

        $this->handler($data)->setup();
    }

    public function testTeardownRemovesEveryStoredSetting(): void
    {
        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response($this->dhlRatesResponse())]);
        $handler = $this->handler($this->setupData() + ['content_description' => 'Gifts']);
        $handler->setup();

        $handler->teardown();

        foreach (ConfigurationEnum::cases() as $key) {
            $this->assertNull($this->company->get($key->value), $key->value);
        }
    }

    public function testClientSendsTheMessageReferenceOnBooking(): void
    {
        $this->configureDhl($this->company);
        $this->fakeDhl([self::DHL_HOST . '/shipments' => Http::response($this->dhlShipmentResponse())]);

        new Client($this->company)->createShipment(['productCode' => 'N'], 'order-uuid-1');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Message-Reference', 'order-uuid-1'));
    }

    public function testClientFailsClearlyWithoutCredentials(): void
    {
        $this->expectException(ValidationException::class);

        new Client(Companies::factory()->create())->rates([]);
    }

    public function testSetupMutationConnectsTheIntegrationAndActivatesIt(): void
    {
        if (! Schema::connection('workflow')->hasColumn('integration_companies', 'apps_id')) {
            $this->markTestSkipped('The workflow database has not run the apps_id migration for integration_companies');
        }

        $this->fakeDhl([self::DHL_HOST . '/rates' => Http::response($this->dhlRatesResponse())]);
        Integrations::firstOrCreate(
            ['name' => IntegrationsEnum::DHL->value, 'apps_id' => 0],
            ['handler' => DhlHandler::class]
        );

        $response = $this->graphQL(
            'mutation($input: DhlSetupInput!) { dhlSetup(input: $input) }',
            ['input' => $this->setupData() + ['region_id' => $this->region->getId(), 'company_id' => $this->company->getId()]]
        );

        $this->assertTrue($response->json('data.dhlSetup') ?? false, $response->getContent());
        $this->assertContains(IntegrationsEnum::DHL->value, $this->activeIntegrationNames());
    }

    private function activeIntegrationNames(): array
    {
        return new ActiveIntegrationsService($this->company)->names();
    }

    private function handler(array $data): DhlHandler
    {
        return new DhlHandler(
            app: app(Apps::class),
            company: $this->company,
            region: $this->region,
            data: $data,
        );
    }

    private function setupData(): array
    {
        return [
            'username' => 'apiUser',
            'password' => 'apiSecret',
            'account_number' => '123456789',
            'origin' => $this->dhlOrigin(),
        ];
    }
}
