<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\OAuth;

use Illuminate\Http\Request;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Connectors\Facebook\Client as FacebookClient;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

class FacebookOAuthProvider implements OAuthProviderInterface
{
    #[Override]
    public function getAuthorizationUrl(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request,
        string $nonce
    ): string {
        $appId = (string) ($receiver->company->get(ConfigurationEnum::APP_ID->value) ?? $app->get(ConfigurationEnum::APP_ID->value));

        if ($appId === '') {
            throw new ValidationException('Facebook App ID is not configured');
        }

        $redirectUrl = $this->getRedirectUrl($receiver, $app);
        $scopes = (string) ($app->get('facebook_scopes') ?? 'pages_read_engagement,pages_manage_metadata,leads_retrieval');
        $graphVersion = (string) ($app->get(ConfigurationEnum::GRAPH_API_VERSION->value) ?? 'v21.0');

        return 'https://www.facebook.com/' . $graphVersion . '/dialog/oauth?' . http_build_query([
            'client_id' => $appId,
            'redirect_uri' => $redirectUrl,
            'state' => $nonce,
            'scope' => $scopes,
            'response_type' => 'code',
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
            $error = (string) ($request->input('error_message') ?? $request->input('error') ?? 'Unknown error');
            throw new ValidationException('Facebook authorization failed: ' . $error);
        }

        $appId = (string) ($receiver->company->get(ConfigurationEnum::APP_ID->value) ?? $app->get(ConfigurationEnum::APP_ID->value));
        $appSecret = (string) ($receiver->company->get(ConfigurationEnum::APP_SECRET->value) ?? $app->get(ConfigurationEnum::APP_SECRET->value));
        $redirectUrl = $this->getRedirectUrl($receiver, $app);
        $graphVersion = (string) ($app->get(ConfigurationEnum::GRAPH_API_VERSION->value) ?? 'v21.0');

        $shortLivedToken = FacebookClient::exchangeCodeForToken($appId, $appSecret, $redirectUrl, $code, $graphVersion);

        $longLivedToken = FacebookClient::exchangeLongLivedToken($appId, $appSecret, $shortLivedToken, $graphVersion);

        $pages = FacebookClient::getUserPages($longLivedToken, $graphVersion);

        $receiver->company->set(
            ConfigurationEnum::USER_ACCESS_TOKEN->value . '-' . $app->getId(),
            $longLivedToken
        );

        $subscribedPages = [];

        /** @var array{id: string, name: string, access_token: string} $page */
        foreach ($pages as $page) {
            $pageKey = ConfigurationEnum::PAGE_ACCESS_TOKEN->value
                . '-' . $app->getId()
                . '-' . $receiver->company->getId()
                . '-' . $page['id'];

            $receiver->company->set($pageKey, [
                'page_id' => $page['id'],
                'page_name' => $page['name'],
                'access_token' => $page['access_token'],
            ]);

            FacebookClient::subscribePageToLeadgen($page['id'], $page['access_token'], $graphVersion);
            $subscribedPages[] = [
                'id' => $page['id'],
                'name' => $page['name'],
            ];
        }

        return [
            'message' => 'Successfully connected to Facebook',
            'pages' => $subscribedPages,
        ];
    }

    #[Override]
    public function getStateKeyPrefix(): string
    {
        return 'facebook_oauth';
    }

    private function getRedirectUrl(ReceiverWebhook $receiver, Apps $app): string
    {
        /** @var array<string, mixed> $config */
        $config = $receiver->configuration;

        $redirectUrl = (string) ($config['oauth_callback_url'] ?? $app->get('facebook-oauth-redirect-url') ?? '');

        if ($redirectUrl === '') {
            $appUrl = (string) config('app.url');
            $redirectUrl = $appUrl . '/v1/oauth/' . $receiver->uuid . '/callback';
        }

        return $redirectUrl;
    }
}
