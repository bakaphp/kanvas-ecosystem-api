<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\OAuth;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Microsoft\Client as MicrosoftClient;
use Kanvas\Connectors\Microsoft\Enums\ConfigurationEnum;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
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
        $store = MicrosoftTokenStore::forReceiver($receiver, $app);
        $clientId = $store->credential(ConfigurationEnum::CLIENT_ID);
        $tenantId = $store->credential(ConfigurationEnum::TENANT_ID);

        if ($clientId === '') {
            throw new ValidationException('Microsoft Client ID is not configured');
        }

        if ($tenantId === '') {
            throw new ValidationException('Microsoft Tenant ID is not configured');
        }

        $redirectUrl = $this->getRedirectUrl($receiver, $app);
        $scopes = $store->authorizationScopes();

        return "https://login.microsoftonline.com/{$tenantId}/oauth2/v2.0/authorize?" . http_build_query([
            'client_id' => $clientId,
            'response_type' => 'code',
            'redirect_uri' => $redirectUrl,
            'response_mode' => 'query',
            'scope' => $scopes,
            'state' => $nonce,
            'prompt' => 'select_account',
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

        $store = MicrosoftTokenStore::forReceiver($receiver, $app);

        $tokenData = MicrosoftClient::exchangeCodeForToken(
            $store->credential(ConfigurationEnum::CLIENT_ID),
            $store->credential(ConfigurationEnum::CLIENT_SECRET),
            $store->credential(ConfigurationEnum::TENANT_ID),
            $this->getRedirectUrl($receiver, $app),
            $code,
            $store->authorizationScopes()
        );

        MicrosoftClient::storeTokens($store, $tokenData);

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

        // Entra accepts only redirect URIs registered in advance, so a per-receiver URL would need
        // one registration per company; the shared one finds the receiver from `state`.
        if ($redirectUrl === '') {
            $redirectUrl = ReceiverWebhook::sharedOAuthCallbackUrl();
        }

        return $redirectUrl;
    }
}
