<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

use function Sentry\captureException;

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

    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
        $this->receiver = $this->webhookRequest->receiverWebhook()->firstOrFail();
    }

    public function handle()
    {
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

            $this->receiver->fireWorkflow(
                WorkflowEnum::AFTER_PROCESS_WEBHOOK->value,
                true,
                [
                    'app' => $this->receiver->app,
                    'company' => $this->receiver->company,
                    'user' => $this->receiver->user,
                ]
            );

            return $results;
        } catch (Throwable $e) {
            //notify via sentry
            Log::error($e->getMessage());
            captureException($e);
            $this->webhookRequest->update([
                'status' => 'failed',
                'exception' => [
                    'code' => $e->getCode(),
                    'message' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ],
            ]);
        }
    }

    abstract public function execute(): array;
}
