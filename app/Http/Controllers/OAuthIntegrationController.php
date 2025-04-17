<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Baka\Support\Str;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Models\ReceiverWebhook;
use PHPShopify\AuthHelper;
use PHPShopify\ShopifySDK;
use Sentry\Laravel\Facade as Sentry;

class OAuthIntegrationController extends BaseController
{
    /**
     * Begin the OAuth process
     */
    public function auth(string $uuid, Request $request): JsonResponse|RedirectResponse|Redirector
    {
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;

        $shopDomain = $receiver->config['shop_domain'] ?? $request->get('shop');
        $shopDomain .= '.myshopify.com';

        // Configure the Shopify SDK with redirect URL
        $redirectUrl = $app->get('shopify-redirect-url');
        $this->configureShopifySDK($app, $shopDomain, $redirectUrl);

        // Generate a nonce for security
        $nonce = Str::random(20);
        session(['shopify_nonce_' . $app->getId() => $nonce]);
        session(['shopify_shop_' . $app->getId() => $shopDomain]);

        // Get the authorization URL
        $scopes = $app->get('shopify_scopes') ?? 'read_products,write_products,read_orders,write_orders';
        $authUrl = AuthHelper::createAuthRequest($scopes, $shopDomain, $nonce);

        return redirect($authUrl);
    }

    /**
     * Handle the OAuth callback from Shopify
     */
    public function callback(string $uuid, Request $request): JsonResponse
    {
        // Validate required parameters
        $shop = $request->get('shop');
        $code = $request->get('code');
        $hmac = $request->get('hmac');

        if (! $shop || ! $code || ! $hmac) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Get the receiver and app
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;

        // Verify shop and nonce from session
        $nonce = session('shopify_nonce_' . $app->getId());
        $sessionShop = session('shopify_shop_' . $app->getId());

        if (! $nonce || $shop !== $sessionShop) {
            return response()->json(['error' => 'Invalid session or shop mismatch'], 400);
        }

        // Configure the Shopify SDK
        $this->configureShopifySDK($app, $shop);

        try {
            // Get the access token
            $accessToken = AuthHelper::getAccessToken($code);

            if (! $accessToken) {
                throw new \Exception('Failed to get access token');
            }

            // Initialize the SDK with the access token
            $config = $this->configureShopifySDK($app, $shop);
            $config['AccessToken'] = $accessToken;
            $shopify = new ShopifySDK($config);

            // Get shop details to verify the token
            $shopInfo = $shopify->Shop->get();

            // Store the token and info in the app
            $app->set('shopify_access_token', [
                'access_token' => $accessToken,
                'shop_info' => $shopInfo,
                'shop_domain' => $shop,
            ]);

            // Clear the session data
            $this->clearSessionData($app);

            return response()->json([
                'success' => true,
                'message' => 'Successfully authenticated with Shopify',
                'shop' => $shopInfo['name'],
            ]);
        } catch (\Exception $e) {
            Sentry::withScope(function ($scope) use ($e, $uuid, $request) {
                $scope->setContext('Request Data', [
                    'uuid' => $uuid,
                    'payload' => $request->all(),
                    'exception' => $e->getMessage(),
                ]);
                Sentry::captureException($e);
            });

            // Clear session data
            $this->clearSessionData($app);

            return response()->json([
                'error' => 'Authentication error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the receiver and app for the given UUID
     */
    private function getReceiverAndApp(string $uuid, Request $request): array|JsonResponse
    {
        $receiver = ReceiverWebhook::where('uuid', $uuid)->notDeleted()->first();

        if (! $receiver) {
            Sentry::withScope(function ($scope) use ($uuid, $request) {
                $scope->setContext('Request Data', [
                    'uuid' => $uuid,
                    'payload' => $request->all(),
                ]);
                Sentry::captureMessage("Receiver not found for UUID: {$uuid}");
            });

            return response()->json(['message' => 'Receiver not found'], 404);
        }

        $app = app(Apps::class);

        if ($app->getId() !== $receiver->apps_id) {
            App::scoped(Apps::class, fn () => $receiver->app);
        }

        return ['receiver' => $receiver, 'app' => $app];
    }

    /**
     * Configure Shopify SDK
     */
    private function configureShopifySDK(Apps $app, string $shopDomain, ?string $redirectUrl = null): array
    {
        $config = [
            'ApiKey' => $app->get('shopify-api-key'),
            'ApiSecret' => $app->get('shopify-api-secret'),
            'ShopUrl' => $shopDomain,
            'ApiVersion' => $app->get('shopify-api-version') ?? '2025-01',
        ];

        if ($redirectUrl) {
            $config['RedirectUrl'] = $redirectUrl;
        }

        // Initialize the SDK
        ShopifySDK::config($config);

        return $config;
    }

    /**
     * Clear session data for the app
     */
    private function clearSessionData(Apps $app): void
    {
        session()->forget([
            'shopify_nonce_' . $app->getId(),
            'shopify_shop_' . $app->getId(),
        ]);
    }
}
