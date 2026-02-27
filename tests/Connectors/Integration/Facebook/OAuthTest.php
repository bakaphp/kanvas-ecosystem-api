<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Facebook;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderFactory;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Connectors\Facebook\OAuth\FacebookOAuthProvider;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Shopify\OAuth\ShopifyOAuthProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Tests\TestCase;

class OAuthTest extends TestCase
{
    public function testFactoryResolvesFacebook(): void
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
                'configuration' => ['oauth_provider' => 'facebook'],
            ]);

        $provider = OAuthProviderFactory::make($receiver);

        $this->assertInstanceOf(OAuthProviderInterface::class, $provider);
        $this->assertInstanceOf(FacebookOAuthProvider::class, $provider);
    }

    public function testFactoryResolvesShopifyByDefault(): void
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
                'configuration' => ['region_id' => 1],
            ]);

        $provider = OAuthProviderFactory::make($receiver);

        $this->assertInstanceOf(ShopifyOAuthProvider::class, $provider);
    }

    public function testFactoryThrowsForUnsupportedProvider(): void
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
                'configuration' => ['oauth_provider' => 'unsupported_provider'],
            ]);

        $this->expectException(ValidationException::class);
        OAuthProviderFactory::make($receiver);
    }

    public function testFacebookAuthorizationUrl(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $fakeAppId = 'test_facebook_app_id_' . fake()->uuid();
        $app->set(ConfigurationEnum::APP_ID->value, $fakeAppId);

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
                    'oauth_provider' => 'facebook',
                    'oauth_callback_url' => 'https://example.com/callback',
                ],
            ]);

        $provider = new FacebookOAuthProvider();
        $request = \Illuminate\Http\Request::create('/oauth/' . $receiver->uuid, 'GET');
        $nonce = fake()->uuid();

        $url = $provider->getAuthorizationUrl($receiver, $app, $request, $nonce);

        $this->assertStringContainsString('https://www.facebook.com/', $url);
        $this->assertStringContainsString('/dialog/oauth', $url);
        $this->assertStringContainsString('client_id=' . $fakeAppId, $url);
        $this->assertStringContainsString('state=' . $nonce, $url);
        $this->assertStringContainsString('scope=pages_read_engagement', $url);
        $this->assertStringContainsString('leads_retrieval', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
        $this->assertStringContainsString('response_type=code', $url);
    }

    public function testFacebookAuthorizationUrlUsesCompanyConfig(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $companyAppId = 'company_fb_app_' . fake()->uuid();
        $company->set(ConfigurationEnum::APP_ID->value, $companyAppId);

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
                    'oauth_provider' => 'facebook',
                    'oauth_callback_url' => 'https://example.com/callback',
                ],
            ]);

        $provider = new FacebookOAuthProvider();
        $request = \Illuminate\Http\Request::create('/oauth/' . $receiver->uuid, 'GET');
        $nonce = fake()->uuid();

        $url = $provider->getAuthorizationUrl($receiver, $app, $request, $nonce);

        $this->assertStringContainsString('client_id=' . $companyAppId, $url);
    }

    public function testFacebookAuthorizationUrlThrowsWithoutAppId(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->del(ConfigurationEnum::APP_ID->value);
        $company->del(ConfigurationEnum::APP_ID->value);

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
                    'oauth_provider' => 'facebook',
                ],
            ]);

        $provider = new FacebookOAuthProvider();
        $request = \Illuminate\Http\Request::create('/oauth/' . $receiver->uuid, 'GET');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Facebook App ID is not configured');
        $provider->getAuthorizationUrl($receiver, $app, $request, 'test-nonce');
    }

    public function testFacebookCallbackThrowsWithoutCode(): void
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
                'configuration' => ['oauth_provider' => 'facebook'],
            ]);

        $provider = new FacebookOAuthProvider();
        $request = \Illuminate\Http\Request::create('/oauth/' . $receiver->uuid . '/callback', 'GET', [
            'error' => 'access_denied',
            'error_message' => 'User denied access',
        ]);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Facebook authorization failed: User denied access');
        $provider->handleCallback($receiver, $app, $request);
    }

    public function testFacebookStateKeyPrefix(): void
    {
        $provider = new FacebookOAuthProvider();
        $this->assertEquals('facebook_oauth', $provider->getStateKeyPrefix());
    }
}
