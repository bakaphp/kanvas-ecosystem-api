<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\OAuth;

use Baka\Support\Str;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderInterface;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;
use PHPShopify\AuthHelper;
use PHPShopify\ShopifySDK;
use RuntimeException;

class ShopifyOAuthProvider implements OAuthProviderInterface
{
    #[Override]
    public function getAuthorizationUrl(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request,
        string $nonce
    ): string {
        $shopDomain = (string) ($request->input('shop') ?? $receiver->configuration['shop_domain']);
        $shopDomain .= '.myshopify.com';

        $redirectUrl = (string) ($receiver->company->get('shopify-redirect-url') ?? $app->get('shopify-redirect-url'));
        $this->configureShopifySDK($app, $shopDomain, $receiver, $redirectUrl);

        $scopes = (string) ($app->get('shopify_scopes') ?? 'read_products,write_products,read_orders,write_orders');

        return (string) AuthHelper::createAuthRequest(
            scopes: $scopes,
            redirectUrl: $redirectUrl,
            state: $nonce,
            return: true,
        );
    }

    #[Override]
    public function handleCallback(
        ReceiverWebhook $receiver,
        Apps $app,
        Request $request
    ): array {
        $shop = (string) $request->input('shop');
        $code = (string) $request->input('code');
        $hmac = (string) $request->input('hmac');
        $_GET = $request->query->all();

        if ($shop === '' || $code === '' || $hmac === '') {
            throw new InvalidArgumentException('Missing required Shopify parameters');
        }

        $region = Regions::getByIdFromCompanyApp((int) $receiver->configuration['region_id'], $receiver->company, $app);

        $this->configureShopifySDK($app, $shop, $receiver);

        $accessToken = AuthHelper::getAccessToken();

        if (! $accessToken) {
            throw new RuntimeException('Failed to get Shopify access token');
        }

        $config = $this->configureShopifySDK($app, $shop, $receiver);
        $config['AccessToken'] = $accessToken;
        $shopify = new ShopifySDK($config);

        $shopInfo = $shopify->Shop->get();

        $accessTokenResult = [
            'access_token' => $accessToken,
            'shop_info' => $shopInfo,
            'shop_domain' => $shop,
        ];

        $receiver->company->set('shopify-access-token-' . $receiver->company->getId() . '-' . $region->getId(), $accessTokenResult);
        $shopifyStoresConfig = $app->get('shopify_stores_config') ?? [];

        /** @var array<string, mixed> $shopifyStoresConfig */
        $shopifyStoresConfig[$shop] = [
            'access_token' => $accessToken,
            'shop_info' => $shopInfo,
        ];
        $app->set('shopify_stores_config', $shopifyStoresConfig);

        return [
            'message' => 'Successfully authenticated with Shopify',
            'shop' => $shopInfo['name'] ?? $shop,
            'shop_domain' => $shop,
        ];
    }

    #[Override]
    public function getStateKeyPrefix(): string
    {
        return 'shopify_oauth';
    }

    /**
     * @return array<string, mixed>
     */
    private function configureShopifySDK(
        Apps $app,
        string $shopDomain,
        ReceiverWebhook $receiver,
        ?string $redirectUrl = null
    ): array {
        if (! Str::startsWith($shopDomain, ['http://', 'https://'])) {
            $shopDomain = 'https://' . $shopDomain;
        }

        $apiKey = $receiver->company->get('shopify-api-key') ?? $app->get('shopify-api-key');
        $apiSecret = $receiver->company->get('shopify-api-secret') ?? $app->get('shopify-api-secret');

        $config = [
            'ApiKey' => $apiKey,
            'ApiSecret' => $apiSecret,
            'SharedSecret' => $apiSecret,
            'ShopUrl' => $shopDomain,
            'ApiVersion' => $app->get('shopify-api-version') ?? '2025-01',
        ];

        if ($redirectUrl !== null && $redirectUrl !== '') {
            $config['RedirectUrl'] = $redirectUrl;
        }

        ShopifySDK::config($config);

        return $config;
    }
}
