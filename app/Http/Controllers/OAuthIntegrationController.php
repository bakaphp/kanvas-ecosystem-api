<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Contracts\OAuthProviderFactory;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Sentry\Laravel\Facade as Sentry;

class OAuthIntegrationController extends BaseController
{
    public function auth(string $uuid, Request $request): JsonResponse|RedirectResponse|Redirector
    {
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;

        $provider = OAuthProviderFactory::make($receiver);

        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();
        $nonce = $webhookRequest->uuid;

        $stateKey = $provider->getStateKeyPrefix() . ':' . $uuid;
        Redis::setex($stateKey, 1800, json_encode([
            'nonce' => $nonce,
            'app_id' => $app->getId(),
        ]));

        $authUrl = $provider->getAuthorizationUrl($receiver, $app, $request, $nonce);

        return redirect()->away($authUrl);
    }

    public function callback(string $uuid, Request $request): JsonResponse|RedirectResponse|Redirector
    {
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;

        $provider = OAuthProviderFactory::make($receiver);

        $stateKey = $provider->getStateKeyPrefix() . ':' . $uuid;
        $stateJson = Redis::get($stateKey);

        if (! $stateJson) {
            return response()->json(['error' => 'OAuth state expired or invalid'], 400);
        }

        /** @var array{nonce?: string} $state */
        $state = json_decode((string) $stateJson, true);
        $nonce = $state['nonce'] ?? null;

        if ($nonce === null) {
            return response()->json(['error' => 'Invalid OAuth state'], 400);
        }

        $receiverCall = ReceiverWebhookCall::where('uuid', $nonce)->notDeleted()->first();

        try {
            $callbackResult = $provider->handleCallback($receiver, $app, $request);

            Redis::del($stateKey);

            if ($receiverCall) {
                $receiverCall->update([
                    'status' => 'success',
                    'results' => $callbackResult,
                ]);
            }

            /** @var array<string, mixed> $config */
            $config = $receiver->configuration;
            $redirectUrl = $config['redirect_url'] ?? null;

            if (is_string($redirectUrl) && filter_var($redirectUrl, FILTER_VALIDATE_URL)) {
                return redirect()->away($redirectUrl);
            }

            return response()->json([
                'success' => true,
                ...$callbackResult,
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

            Redis::del($stateKey);

            if ($receiverCall) {
                $receiverCall->update([
                    'status' => 'failed',
                    'exception' => [
                        'code' => $e->getCode(),
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ],
                ]);
            }

            return response()->json([
                'error' => 'Authentication error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * @return array{receiver: ReceiverWebhook, app: Apps}|JsonResponse
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
}
