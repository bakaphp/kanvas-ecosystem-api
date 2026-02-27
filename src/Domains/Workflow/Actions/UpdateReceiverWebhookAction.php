<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;

class UpdateReceiverWebhookAction
{
    public function __construct(
        protected readonly ReceiverWebhook $receiver,
        protected readonly ReceiverWebhookData $data,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        return DB::connection('workflow')->transaction(function () {
            $this->receiver->action_id = $this->data->action->getId();
            $this->receiver->name = $this->data->name;
            $this->receiver->description = $this->data->description;
            $this->receiver->configuration = $this->data->configuration;
            $this->receiver->is_active = $this->data->is_active;
            $this->receiver->run_async = $this->data->run_async;
            $this->receiver->saveOrFail();

            return $this->receiver;
        });
    }
}
