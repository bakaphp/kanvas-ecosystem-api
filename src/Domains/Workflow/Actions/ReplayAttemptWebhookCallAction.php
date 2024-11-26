<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Kanvas\Workflow\Models\ReceiverWebhookCall;

class ReplayAttemptWebhookCallAction
{
    public function __construct(
        public ReceiverWebhookCall $receiverWebhookCall
    ) {
    }

    public function execute(): void
    {
        $receiver = $this->receiverWebhookCall->receiverWebhook;
        $job = new $receiver->action->model_name($this->receiverWebhookCall);
        dispatch($job);
    }
}
