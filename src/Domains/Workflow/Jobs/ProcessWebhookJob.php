<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;
use Throwable;

abstract class ProcessWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public $failOnTimeout = false;
    protected ReceiverWebhook $receiver;
    protected int $failedReturnHttpCode = 500;

    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
        $this->receiver = $this->webhookRequest->receiverWebhook()->firstOrFail();
    }

    public function handle(): ?array
    {
        $results = null;

        try {
            config(['laravel-model-caching.disabled' => true]);
            Auth::loginUsingId($this->receiver->user->getId());
            $this->overwriteAppService($this->receiver->app);
            $this->overwriteAppServiceLocation($this->receiver->company->defaultBranch);

            $results = $this->execute();

            $this->webhookRequest->update([
                'status' => 'success',
                'results' => $results,
            ]);
        } catch (Throwable $e) {
            //Log::error($e->getMessage());
            report($e);
            $this->webhookRequest->update([
                'status' => 'failed',
                'exception' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
        }

        return $results;
    }

    public function getFailedReturnHttpCode(): int
    {
        return $this->failedReturnHttpCode;
    }

    /**
     * Connector-specific request authentication, evaluated before the webhook call's uploaded
     * files are persisted. Override per connector that needs it; the default trusts the request
     * — the existing behavior for every connector.
     */
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        return true;
    }

    /**
     * In-band answer to a provider's handshake request — a non-null return short-circuits the
     * receiver: the array is the JSON response and the job is never dispatched.
     *
     * For providers that validate the endpoint by POSTing a nonce they expect echoed back in the
     * response body (Slack's `url_verification` challenge). Those requests carry no work, so they
     * must not ride the queue — and the alternative, running the whole receiver synchronously just
     * to reach a `return`, puts every real event inside the provider's ack timeout.
     */
    public static function handshakeResponse(Request $request, ReceiverWebhook $receiver): ?array
    {
        return null;
    }

    /**
     * Whether the request's uploaded files must be persisted before this job runs. A receiver can
     * also opt in with `capture_files`, but a job that cannot work without the files declares it
     * here rather than trusting whoever wired the receiver: the multipart request is gone by the
     * time the job runs, so a missing flag drops the files with nothing logged anywhere.
     */
    public static function capturesFiles(): bool
    {
        return false;
    }

    abstract public function execute(): array;
}
