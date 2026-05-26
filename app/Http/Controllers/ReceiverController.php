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
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;

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
            return response()->json(['message' => 'Receiver not found'], 404);
        }

        $app = app(Apps::class);

        if ($app->getId() !== $receiver->apps_id) {
            App::scoped(Apps::class, fn () => $receiver->app);
        }

        $jobClass = $receiver->action?->model_name;
        if (is_string($jobClass)
            && is_a($jobClass, ProcessWebhookJob::class, true)
            && ! $jobClass::authenticateRequest($request, $receiver)
        ) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $webhookRequest = new ProcessWebhookAttemptAction(
            $receiver,
            $request
        )->execute();

        $jobClass = $receiver->action->model_name;
        $job = new $jobClass($webhookRequest);

        if ($receiver->runAsync()) {
            dispatch($job);

            return response()->json(['message' => 'Receiver processed']);
        }

        $response = $job->handle();

        if (! is_array($response)) {
            return response()->json(
                ['message' => "Something went wrong, we've notified support"],
                method_exists($job, 'getFailedReturnHttpCode') ? $job->getFailedReturnHttpCode() : 500
            );
        }

        // `status` in $response is dual-purpose: numeric values are interpreted as the
        // HTTP code, non-int values (e.g. string `"success"|"error"` per a webhook's own
        // response envelope) stay in the body and HTTP defaults to 200.
        $status = is_int($response['status'] ?? null) ? $response['status'] : 200;

        return response()->json(
            array_merge(['message' => 'Receiver processed'], $response),
            $status
        );
    }
}
