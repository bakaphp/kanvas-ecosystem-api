<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Illuminate\Http\Request;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class ProcessWebhookAttemptAction
{
    public function __construct(
        protected ReceiverWebhook $receiver,
        protected Request $request,
    ) {
    }

    public function execute(): ReceiverWebhookCall
    {
        $webhookCall = ReceiverWebhookCall::create([
            'receiver_webhooks_id' => $this->receiver->getId(),
            'url' => $this->request->fullUrl(),
            'headers' => $this->request->headers->all(),
            'payload' => $this->request->input(),
        ]);

        return $webhookCall;
    }
}
