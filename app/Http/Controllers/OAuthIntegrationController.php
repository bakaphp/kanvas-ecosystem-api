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
    /** Long enough for a vendor's explanation, short enough to stay a sane query string. */
    private const int REDIRECT_MESSAGE_MAX = 300;

    public function auth(string $uuid, Request $request): JsonResponse|RedirectResponse|Redirector
    {
        $result = $this->getReceiverAndApp($uuid, $request);

        if ($result instanceof JsonResponse) {
            return $result;
        }

        ['receiver' => $receiver, 'app' => $app] = $result;

        $provider = OAuthProviderFactory::make($receiver);

        $webhookRequest = new ProcessWebhookAttemptAction($receiver, $request)->execute();
        $nonce = (string) $webhookRequest->uuid;

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
        $redirectUrl = $this->redirectUrlOf($receiver);

        try {
            $callbackResult = $provider->handleCallback($receiver, $app, $request);

            Redis::del($stateKey);

            if ($receiverCall) {
                $receiverCall->update([
                    'status' => 'success',
                    'results' => $callbackResult,
                ]);
            }

            if ($redirectUrl !== null) {
                return redirect()->away($this->withResult($redirectUrl, ['status' => 'success']));
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

            // Back to the UI on failure as well: otherwise the person is stranded on a raw JSON page on
            // the API's domain, and the UI never learns why the connection didn't happen.
            if ($redirectUrl !== null) {
                return redirect()->away($this->withResult($redirectUrl, [
                    'status' => 'error',
                    'message' => mb_substr($e->getMessage(), 0, self::REDIRECT_MESSAGE_MAX),
                ]));
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

    private function redirectUrlOf(ReceiverWebhook $receiver): ?string
    {
        $configuration = is_array($receiver->configuration) ? $receiver->configuration : [];
        $redirectUrl = $configuration['redirect_url'] ?? null;

        return is_string($redirectUrl) && filter_var($redirectUrl, FILTER_VALIDATE_URL) ? $redirectUrl : null;
    }

    /**
     * Appends the result to the UI's URL, keeping any `#fragment` last — single-page apps route on the
     * hash, and a query string placed after it would never reach the server or the router.
     *
     * @param array<string, string> $params
     */
    private function withResult(string $url, array $params): string
    {
        [$base, $fragment] = array_pad(explode('#', $url, 2), 2, null);
        $separator = str_contains((string) $base, '?') ? '&' : '?';

        return $base . $separator . http_build_query($params) . ($fragment !== null ? '#' . $fragment : '');
    }
}
