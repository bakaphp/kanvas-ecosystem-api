<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Baka\Support\Str;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use PHPShopify\AuthHelper;
use PHPShopify\ShopifySDK;
use Sentry\Laravel\Facade as Sentry;

/**
 * TODO like we started with receivers, this is tied in to shopify
 * but we need to make it oauth agnostic
 * @package App\Http\Controllers
 */
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

        $shopDomain = $receiver->configuration['shop_domain'] ?? $request->get('shop');
        $shopDomain .= '.myshopify.com';

        // Configure the Shopify SDK with redirect URL
        $redirectUrl = $app->get('shopify-redirect-url');
        $this->configureShopifySDK($app, $shopDomain, $redirectUrl);

        // Generate a nonce for security
        $webhookRequest = (new ProcessWebhookAttemptAction($receiver, $request))->execute();
        $nonce = $webhookRequest->uuid;

        // Store state in Redis instead of session
        $stateKey = 'shopify_oauth:' . $uuid;
        Redis::setex($stateKey, 1800, json_encode([
            'nonce' => $nonce,
            'shop' => $shopDomain,
            'app_id' => $app->getId(),
        ]));

        // Get the authorization URL
        $scopes = $app->get('shopify_scopes') ?? 'read_products,write_products,read_orders,write_orders';

        $authUrl = AuthHelper::createAuthRequest(
            scopes: $scopes,
            redirectUrl: $redirectUrl,
            state: $nonce,
            return: true,
        );

        return redirect()->away($authUrl);
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
        $_GET = $request->query->all();

        if (! $shop || ! $code || ! $hmac) {
            return response()->json(['error' => 'Missing required parameters'], 400);
        }

        // Get the receiver and app
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;
        $region = Regions::getByIdFromCompanyApp($receiver->configuration['region_id'], $receiver->company, $app);

        // Retrieve state from Redis
        $stateKey = 'shopify_oauth:' . $uuid;
        $stateJson = Redis::get($stateKey);

        if (! $stateJson) {
            return response()->json(['error' => 'OAuth state expired or invalid'], 400);
        }

        $state = json_decode($stateJson, true);
        $nonce = $state['nonce'] ?? null;
        $sessionShop = $state['shop'] ?? null;

        if (! $nonce || $shop !== $sessionShop) {
            return response()->json([
                'error' => 'Invalid state or shop mismatch',
                'details' => [
                    'expected_shop' => $sessionShop,
                    'received_shop' => $shop,
                ],
            ], 400);
        }

        $receiverCall = ReceiverWebhookCall::where('uuid', $nonce)->notDeleted()->first();

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

            //$app->getId() . '-' . $company->getId() . '-' . $region->getId()
            // Store the token and info in the app
            $accessTokenResult = [
                'access_token' => $accessToken,
                'shop_info' => $shopInfo,
                'shop_domain' => $shop,
            ];
            $app->set('shopify-access-token-' . $receiver->company->id . '-' . $region->id, $accessTokenResult);

            // Clean up Redis state
            $this->clearRedisState($uuid);

            $receiverCall->update([
                'status' => 'success',
                'results' => $accessTokenResult,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Successfully authenticated with Shopify',
                'shop' => $shopInfo['name'],
            ]);
        } catch (Exception $e) {
            Sentry::withScope(function ($scope) use ($e, $uuid, $request) {
                $scope->setContext('Request Data', [
                    'uuid' => $uuid,
                    'payload' => $request->all(),
                    'exception' => $e->getMessage(),
                ]);
                Sentry::captureException($e);
            });

            // Clean up Redis state
            $this->clearRedisState($uuid);

            $receiverCall->update([
                'status' => 'failed',
                'exception' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);

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

        return ['receiver' => $receiver, 'app' => $receiver->app];
    }

    /**
     * Configure Shopify SDK
     */
    private function configureShopifySDK(Apps $app, string $shopDomain, ?string $redirectUrl = null): array
    {
        if (! Str::startsWith($shopDomain, ['http://', 'https://'])) {
            $shopDomain = 'https://' . $shopDomain;
        }

        $config = [
            'ApiKey' => $app->get('shopify-api-key'),
            'ApiSecret' => $app->get('shopify-api-secret'),
            'SharedSecret' => $app->get('shopify-api-secret'),
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
     * Clear Redis state data
     */
    private function clearRedisState(string $uuid): void
    {
        $stateKey = 'shopify_oauth:' . $uuid;
        Redis::del($stateKey);
    }
}
