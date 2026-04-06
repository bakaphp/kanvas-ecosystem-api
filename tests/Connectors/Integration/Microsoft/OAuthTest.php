<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Microsoft;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderFactory;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\OAuth\MicrosoftOAuthProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    public function testFactoryResolvesMicrosoft(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['oauth_provider' => 'microsoft'],
            ]);

        $provider = OAuthProviderFactory::make($receiver);

        $this->assertInstanceOf(OAuthProviderInterface::class, $provider);
        $this->assertInstanceOf(MicrosoftOAuthProvider::class, $provider);
    }

    public function testMicrosoftAuthorizationUrl(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $fakeClientId = 'test_microsoft_client_id_' . fake()->uuid();
        $fakeTenantId = 'test_microsoft_tenant_id_' . fake()->uuid();
        $app->set(ConfigurationEnum::CLIENT_ID->value, $fakeClientId);
        $app->set(ConfigurationEnum::TENANT_ID->value, $fakeTenantId);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'oauth_provider' => 'microsoft',
                    'oauth_callback_url' => 'https://example.com/callback',
                ],
            ]);

        $provider = new MicrosoftOAuthProvider();
        $request = Request::create('/oauth/' . $receiver->uuid, 'GET');
        $nonce = fake()->uuid();

        $url = $provider->getAuthorizationUrl($receiver, $app, $request, $nonce);

        $this->assertStringContainsString('https://login.microsoftonline.com/', $url);
        $this->assertStringContainsString($fakeTenantId, $url);
        $this->assertStringContainsString('/oauth2/v2.0/authorize', $url);
        $this->assertStringContainsString('client_id=' . $fakeClientId, $url);
        $this->assertStringContainsString('state=' . $nonce, $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('scope=', $url);
        $this->assertStringContainsString('Mail.Read', $url);
        $this->assertStringContainsString('Mail.Send', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
    }

    public function testMicrosoftAuthorizationUrlUsesCompanyConfig(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $companyClientId = 'company_msft_client_' . fake()->uuid();
        $companyTenantId = 'company_msft_tenant_' . fake()->uuid();
        $company->set(ConfigurationEnum::CLIENT_ID->value, $companyClientId);
        $company->set(ConfigurationEnum::TENANT_ID->value, $companyTenantId);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'oauth_provider' => 'microsoft',
                    'oauth_callback_url' => 'https://example.com/callback',
                ],
            ]);

        $provider = new MicrosoftOAuthProvider();
        $request = Request::create('/oauth/' . $receiver->uuid, 'GET');
        $nonce = fake()->uuid();

        $url = $provider->getAuthorizationUrl($receiver, $app, $request, $nonce);

        $this->assertStringContainsString('client_id=' . $companyClientId, $url);
        $this->assertStringContainsString($companyTenantId, $url);
    }

    public function testMicrosoftAuthorizationUrlThrowsWithoutClientId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->del(ConfigurationEnum::CLIENT_ID->value);
        $company->del(ConfigurationEnum::CLIENT_ID->value);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'oauth_provider' => 'microsoft',
                ],
            ]);

        $provider = new MicrosoftOAuthProvider();
        $request = Request::create('/oauth/' . $receiver->uuid, 'GET');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Microsoft Client ID is not configured');
        $provider->getAuthorizationUrl($receiver, $app, $request, 'test-nonce');
    }

    public function testMicrosoftAuthorizationUrlThrowsWithoutTenantId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $fakeClientId = 'test_microsoft_client_id_' . fake()->uuid();
        $app->set(ConfigurationEnum::CLIENT_ID->value, $fakeClientId);
        $app->del(ConfigurationEnum::TENANT_ID->value);
        $company->del(ConfigurationEnum::TENANT_ID->value);

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    'oauth_provider' => 'microsoft',
                ],
            ]);

        $provider = new MicrosoftOAuthProvider();
        $request = Request::create('/oauth/' . $receiver->uuid, 'GET');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Microsoft Tenant ID is not configured');
        $provider->getAuthorizationUrl($receiver, $app, $request, 'test-nonce');
    }

    public function testMicrosoftCallbackThrowsWithoutCode(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $action = WorkflowAction::firstOrCreate(
            ['model_name' => OAuthCallbackJob::class],
            ['name' => 'OAuth Callback']
        );

        $receiver = ReceiverWebhook::factory()
            ->app($app->getId())
            ->user($user->getId())
            ->company($company->getId())
            ->create([
                'action_id' => $action->getId(),
                'configuration' => ['oauth_provider' => 'microsoft'],
            ]);

        $provider = new MicrosoftOAuthProvider();
        $request = Request::create('/oauth/' . $receiver->uuid . '/callback', 'GET', [
            'error' => 'access_denied',
            'error_description' => 'User denied access',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Microsoft authorization failed: User denied access');
        $provider->handleCallback($receiver, $app, $request);
    }

    public function testMicrosoftStateKeyPrefix(): void
    {
        $provider = new MicrosoftOAuthProvider();
        $this->assertEquals('microsoft_oauth', $provider->getStateKeyPrefix());
    }
}
