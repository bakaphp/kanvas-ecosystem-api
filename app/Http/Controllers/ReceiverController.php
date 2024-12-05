<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\App;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Actions\ProcessWebhookAttemptAction;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Sentry\Laravel\Facade as Sentry;

class ReceiverController extends BaseController
{
    /**
     * Handle webhook receiver based on UUID.
     *
     * @throws BindingResolutionException
     */
    public function store(string $uuid, Request $request): JsonResponse
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

        $webhookRequest = (new ProcessWebhookAttemptAction($receiver, $request))->execute();
        $jobClass = $receiver->action->model_name;
        $job = new $jobClass($webhookRequest);

        if ($receiver->runAsync()) {
            dispatch($job);

            return response()->json(['message' => 'Receiver processed']);
        }

        $response = $job->handle();
        $status = $response['status'] ?? 200;

        return response()->json(
            array_merge(['message' => 'Receiver processed'], $response),
            $status
        );
    }
}
