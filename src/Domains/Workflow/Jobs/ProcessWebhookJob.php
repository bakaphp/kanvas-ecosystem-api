<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Bouncer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Kanvas\AccessControlList\Enums\RolesEnums;
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

            return $results;
        } catch (Throwable $e) {
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
