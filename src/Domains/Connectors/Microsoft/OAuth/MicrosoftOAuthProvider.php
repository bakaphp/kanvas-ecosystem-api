<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Microsoft\Client as MicrosoftClient;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

class MicrosoftOAuthProvider implements OAuthProviderInterface
{
    #[Override]
    public function getAuthorizationUrl(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request,
        string $nonce
    ): string {
        $clientId = (string) ($receiver->company->get(ConfigurationEnum::CLIENT_ID->value) ?? $app->get(ConfigurationEnum::CLIENT_ID->value));
        $tenantId = (string) ($receiver->company->get(ConfigurationEnum::TENANT_ID->value) ?? $app->get(ConfigurationEnum::TENANT_ID->value));

        if ($clientId === '') {
            throw new ValidationException('Microsoft Client ID is not configured');
        }

        if ($tenantId === '') {
            throw new ValidationException('Microsoft Tenant ID is not configured');
        }

        $redirectUrl = $this->getRedirectUrl($receiver, $app);
        $scopes = (string) ($app->get('microsoft_scopes') ?? 'offline_access Mail.Read Mail.Send Mail.ReadWrite');

        return "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?" . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUrl,
            'response_mode' => 'query',
            'scope' => $scopes,
            'state' => $nonce,
        ]);
    }

    #[Override]
    public function handleCallback(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request
    ): array {
        $code = (string) $request->input('code');

        if ($code === '') {
            $error = (string) ($request->input('error_description') ?? $request->input('error') ?? 'Unknown error');
            throw new ValidationException('Microsoft authorization failed: ' . $error);
        }

        $clientId = (string) ($receiver->company->get(ConfigurationEnum::CLIENT_ID->value) ?? $app->get(ConfigurationEnum::CLIENT_ID->value));
        $clientSecret = (string) ($receiver->company->get(ConfigurationEnum::CLIENT_SECRET->value) ?? $app->get(ConfigurationEnum::CLIENT_SECRET->value));
        $tenantId = (string) ($receiver->company->get(ConfigurationEnum::TENANT_ID->value) ?? $app->get(ConfigurationEnum::TENANT_ID->value));
        $redirectUrl = $this->getRedirectUrl($receiver, $app);

        $tokenData = MicrosoftClient::exchangeCodeForToken(
            $clientId,
            $clientSecret,
            $tenantId,
            $redirectUrl,
            $code
        );

        $configPrefix = '-' . $app->getId() . '-' . $receiver->company->getId();

        $receiver->company->set(
            ConfigurationEnum::ACCESS_TOKEN->value . $configPrefix,
            $tokenData['access_token']
        );

        $receiver->company->set(
            ConfigurationEnum::REFRESH_TOKEN->value . $configPrefix,
            $tokenData['refresh_token']
        );

        $receiver->company->set(
            ConfigurationEnum::TOKEN_EXPIRES_AT->value . $configPrefix,
            Carbon::now()->addSeconds($tokenData['expires_in'])->toIso8601String()
        );

        $userProfile = MicrosoftClient::getUserProfile($tokenData['access_token']);

        return [
            'message' => 'Successfully connected to Microsoft',
            'user' => [
                'id' => $userProfile['id'] ?? '',
                'displayName' => $userProfile['displayName'] ?? '',
                'mail' => $userProfile['mail'] ?? $userProfile['userPrincipalName'] ?? '',
            ],
        ];
    }

    #[Override]
    public function getStateKeyPrefix(): string
    {
        return 'microsoft_oauth';
    }

    private function getRedirectUrl(ReceiverWebhook $receiver, Apps $app): string
    {
        /** @var array<string, mixed> $config */
        $config = $receiver->configuration;

        $redirectUrl = (string) ($config['oauth_callback_url'] ?? $app->get('microsoft-oauth-redirect-url') ?? '');

        if ($redirectUrl === '') {
            $appUrl = (string) config('app.url');
            $redirectUrl = $appUrl . '/v1/oauth/' . $receiver->uuid . '/callback';
        }

        return $redirectUrl;
    }
}
