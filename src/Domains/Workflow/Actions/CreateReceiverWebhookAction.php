<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;

class CreateReceiverWebhookAction
{
    public function __construct(
        protected readonly ReceiverWebhookData $data,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        return DB::connection('workflow')->transaction(function () {
            $receiver = new ReceiverWebhook();
            $receiver->apps_id = $this->data->app->getId();
            $receiver->companies_id = $this->data->company->getId();
            $receiver->users_id = $this->data->user->getId();
            $receiver->action_id = $this->data->action->getId();
            $receiver->name = $this->data->name;
            $receiver->description = $this->data->description;
            $receiver->configuration = $this->data->configuration;
            $receiver->is_active = $this->data->is_active;
            $receiver->run_async = $this->data->run_async;
            $receiver->saveOrFail();

            return $receiver;
        });
    }
}
