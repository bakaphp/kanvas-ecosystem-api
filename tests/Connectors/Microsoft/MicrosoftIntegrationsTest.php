<?php

declare(strict_types=1);

namespace Tests\Connectors\Microsoft;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\Handlers\MicrosoftHandler;
use Kanvas\Connectors\Microsoft\Handlers\OneDriveHandler;
use Kanvas\Connectors\Microsoft\OAuth\MicrosoftOAuthProvider;
use Kanvas\Connectors\Microsoft\Support\MicrosoftScopes;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Regions\Models\Regions;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Workflow\Models\ReceiverWebhook;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Microsoft\Concerns\KeepsMicrosoftSettings;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

/**
 * Serial: both connections live in app and company settings, which every process shares.
 */
#[Group('serial')]
final class MicrosoftIntegrationsTest extends TestCase
{
    use DatabaseTransactions;
    use InventoryCases;
    use KeepsMicrosoftSettings;

    protected $connectionsToTransact = [null, 'workflow', 'inventory'];

    private Apps $kanvasApp;
    private Companies $company;
    private Regions $region;
    private Integrations $appDrive;
    private Integrations $companyAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $user = auth()->user();
        $this->kanvasApp = app(Apps::class);
        $this->company = $user->getCurrentCompany();
        $this->region = $this->createDefaultRegion($this->company, $this->kanvasApp, $user);
        $this->appDrive = $this->integration(OneDriveHandler::class);
        $this->companyAccount = $this->integration(MicrosoftHandler::class);

        $this->keepMicrosoftSettings($this->kanvasApp, $this->company);
        $this->kanvasApp->set(ConfigurationEnum::CLIENT_ID->value, 'app-client');
        $this->kanvasApp->set(ConfigurationEnum::CLIENT_SECRET->value, 'app-secret');
        $this->kanvasApp->set(ConfigurationEnum::TENANT_ID->value, 'common');
        $this->kanvasApp->del(ConfigurationEnum::OAUTH_RETURN_URL->value);
        $this->kanvasApp->del('microsoft-oauth-redirect-url');
        $this->company->del(ConfigurationEnum::CLIENT_ID->value);

        if (! $user->isOwnerOf($this->kanvasApp)) {
            $user->assignOwnerOf($this->kanvasApp);
        }
    }

    protected function tearDown(): void
    {
        $this->restoreMicrosoftSettings();

        parent::tearDown();
    }

    public function test_saving_prepares_one_consent_receiver_per_connection(): void
    {
        $appFirst = $this->save($this->appDrive, []);
        $appSecond = $this->save($this->appDrive, []);
        $company = $this->save($this->companyAccount, []);

        $this->assertSame($appFirst['oauth_url'], $appSecond['oauth_url']);
        $this->assertNotSame($appFirst['oauth_url'], $company['oauth_url']);

        $appReceiver = $this->receiverOf($appFirst);
        $companyReceiver = $this->receiverOf($company);

        $this->assertSame(MicrosoftTokenStore::ONEDRIVE_CONNECTION, $appReceiver->configuration[MicrosoftTokenStore::RECEIVER_CONNECTION]);
        $this->assertArrayNotHasKey(MicrosoftTokenStore::RECEIVER_CONNECTION, $companyReceiver->configuration);
        $this->assertSame('microsoft', $companyReceiver->configuration['oauth_provider']);
        $this->assertSame(OAuthCallbackJob::class, $companyReceiver->action->model_name);
        $this->assertSame($this->statusId(StatusEnum::ACTIVE), IntegrationsCompany::getById((int) $company['id'])->status_id);
    }

    public function test_each_connection_keeps_its_own_root_folder(): void
    {
        $this->save($this->appDrive, ['root_folder' => ' Compartido//Ventas/ ']);
        $this->save($this->companyAccount, ['root_folder' => '/ACME']);

        $this->assertSame('/Compartido/Ventas', $this->appStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));
        $this->assertSame('/ACME', $this->companyStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));

        $this->save($this->appDrive, ['root_folder' => '']);
        $this->assertSame('', $this->appStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));
        $this->assertSame('/ACME', $this->companyStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));
    }

    public function test_a_root_folder_that_climbs_out_is_refused(): void
    {
        $this->request($this->companyAccount, ['root_folder' => '/Clientes/../Privado'])
            ->assertGraphQLErrorMessage('root_folder may not contain "." or ".." segments.');
    }

    public function test_the_frontend_redirect_wins_over_the_app_default_and_must_be_http(): void
    {
        $this->kanvasApp->set(ConfigurationEnum::OAUTH_RETURN_URL->value, 'https://app.example.com/default');

        $this->assertSame('https://app.example.com/default', $this->receiverOf($this->save($this->appDrive, []))->configuration['redirect_url']);
        $this->assertSame(
            'https://app.example.com/integrations#onedrive',
            $this->receiverOf($this->save($this->appDrive, ['redirect_url' => 'https://app.example.com/integrations#onedrive']))
                ->configuration['redirect_url']
        );

        $this->request($this->appDrive, ['redirect_url' => 'javascript:alert(1)'])
            ->assertGraphQLErrorMessage('redirect_url must be an http or https URL.');
    }

    public function test_only_the_app_owner_connects_the_app_drive_but_any_member_connects_their_company(): void
    {
        $this->actingAs(Users::factory()->create());

        $this->expectExceptionMessage('Only the app owner can connect the app\'s OneDrive.');
        $this->handler(OneDriveHandler::class)->setup();
    }

    public function test_a_company_member_connects_their_company_account(): void
    {
        $this->actingAs(Users::factory()->create());

        $this->assertTrue($this->handler(MicrosoftHandler::class)->setup());
    }

    public function test_saving_without_an_app_registration_is_refused(): void
    {
        $this->kanvasApp->del(ConfigurationEnum::CLIENT_SECRET->value);

        $this->request($this->appDrive, [])
            ->assertGraphQLErrorMessage('Microsoft is not configured for this app: microsoft_client_secret is missing.');
    }

    public function test_each_consent_asks_for_its_own_scopes_and_returns_to_the_shared_callback(): void
    {
        $this->company->set(ConfigurationEnum::CLIENT_ID->value, 'company-override');

        $app = $this->authorizationQuery($this->receiverOf($this->save($this->appDrive, [])));
        $company = $this->authorizationQuery($this->receiverOf($this->save($this->companyAccount, [])));

        $this->assertSame('app-client', $app['client_id']);
        $this->assertSame(MicrosoftScopes::ONEDRIVE, $app['scope']);
        $this->assertSame('company-override', $company['client_id']);
        $this->assertStringContainsString('Mail.Read', $company['scope']);
        $this->assertStringContainsString('Files.ReadWrite', $company['scope']);
        $this->assertSame(ReceiverWebhook::sharedOAuthCallbackUrl(), $app['redirect_uri']);
        $this->assertSame(ReceiverWebhook::sharedOAuthCallbackUrl(), $company['redirect_uri']);
    }

    public function test_each_callback_stores_its_tokens_on_its_own_connection(): void
    {
        $appReceiver = $this->receiverOf($this->save($this->appDrive, []));
        $companyReceiver = $this->receiverOf($this->save($this->companyAccount, []));

        $this->completeConsent($appReceiver, 'app-token');
        $this->assertSame('app-token', $this->appStore()->get(ConfigurationEnum::ACCESS_TOKEN));
        $this->assertSame('', $this->companyStore()->get(ConfigurationEnum::ACCESS_TOKEN));

        $this->completeConsent($companyReceiver, 'company-token');
        $this->assertSame('company-token', $this->companyStore()->get(ConfigurationEnum::ACCESS_TOKEN));
        $this->assertSame('app-token', $this->appStore()->get(ConfigurationEnum::ACCESS_TOKEN));
    }

    public function test_disconnecting_one_connection_leaves_the_other(): void
    {
        $app = $this->save($this->appDrive, ['root_folder' => '/Compartido']);
        $company = $this->save($this->companyAccount, ['root_folder' => '/ACME']);
        $this->appStore()->set(ConfigurationEnum::REFRESH_TOKEN, 'app-refresh');
        $this->companyStore()->set(ConfigurationEnum::REFRESH_TOKEN, 'company-refresh');
        $this->companyStore()->set(ConfigurationEnum::LAST_SYNC_AT, '2026-11-09T00:00:00Z');
        $companyReceiver = $this->receiverOf($company);

        $this->remove($company);

        $this->assertSame('', $this->companyStore()->get(ConfigurationEnum::REFRESH_TOKEN));
        $this->assertSame('', $this->companyStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));
        $this->assertSame('', $this->companyStore()->get(ConfigurationEnum::LAST_SYNC_AT));
        $this->assertTrue((bool) $companyReceiver->fresh()->is_deleted);
        $this->assertSame('app-refresh', $this->appStore()->get(ConfigurationEnum::REFRESH_TOKEN));
        $this->assertSame('/Compartido', $this->appStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));

        $this->remove($app);

        $this->assertSame('', $this->appStore()->get(ConfigurationEnum::REFRESH_TOKEN));
        $this->assertSame('', $this->appStore()->get(ConfigurationEnum::ONEDRIVE_ROOT_FOLDER));
    }

    private function integration(string $handler): Integrations
    {
        return Integrations::create([
            'apps_id' => 0,
            'name' => uniqid('microsoft_'),
            'type' => 'oauth',
            'handler' => $handler,
            'config' => ['root_folder' => ['type' => 'text', 'required' => false]],
        ]);
    }

    private function handler(string $class): MicrosoftHandler|OneDriveHandler
    {
        return new $class(
            app: $this->kanvasApp,
            company: $this->company,
            region: $this->region,
            data: [],
        );
    }

    private function appStore(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forAppOneDrive($this->kanvasApp);
    }

    private function companyStore(): MicrosoftTokenStore
    {
        return MicrosoftTokenStore::forCompanyMail($this->kanvasApp, $this->company);
    }

    private function receiverOf(array $saved): ReceiverWebhook
    {
        return IntegrationsCompany::getById((int) $saved['id'])->getOAuthReceiver();
    }

    /**
     * @return array<string, string>
     */
    private function authorizationQuery(ReceiverWebhook $receiver): array
    {
        $url = new MicrosoftOAuthProvider()->getAuthorizationUrl(
            $receiver,
            $this->kanvasApp,
            new Request(),
            'nonce'
        );
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query;
    }

    /**
     * A fresh client per consent: Http::fake() stubs accumulate, and the first one would answer both.
     */
    private function completeConsent(ReceiverWebhook $receiver, string $accessToken): void
    {
        Http::swap(new HttpFactory());
        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => $accessToken,
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
                'scope' => MicrosoftScopes::ONEDRIVE,
            ]),
            'graph.microsoft.com/v1.0/me' => Http::response(['id' => 'u1', 'displayName' => 'Owner']),
        ]);

        new MicrosoftOAuthProvider()->handleCallback($receiver, $this->kanvasApp, new Request(['code' => 'abc']));
    }

    private function remove(array $saved): void
    {
        $this->graphQL('mutation($id: ID!) { removeIntegrationCompany(id: $id) }', ['id' => $saved['id']])
            ->assertJson(['data' => ['removeIntegrationCompany' => true]]);
    }

    /**
     * @return array{id: string, oauth_url: string|null}
     */
    private function save(Integrations $integration, array $config): array
    {
        return $this->graphQLData($this->request($integration, $config), 'integrationCompany');
    }

    private function request(Integrations $integration, array $config): TestResponse
    {
        return $this->graphQL('
            mutation($input: IntegrationsCompaniesInput!) {
                integrationCompany(input: $input) {
                    id
                    oauth_url
                }
            }
        ', [
            'input' => [
                'integration' => ['id' => $integration->getId()],
                'region' => ['id' => $this->region->getId()],
                'company_id' => $this->company->getId(),
                'config' => $config,
            ],
        ]);
    }

    private function statusId(StatusEnum $status): int
    {
        return Status::where('slug', $status->value)
            ->where('apps_id', 0)
            ->firstOrFail()
            ->getId();
    }
}
