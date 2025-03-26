<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

class LeadReceiverObserver
{
    public function created(LeadReceiver $leadReceiver): void
    {
        $action = Action::where('model_name', CreateLeadsFromReceiverJob::class)->first();

        if ($action) {
            $receiverWorkflow = new ReceiverWebhook();
            $receiverWorkflow->uuid = $leadReceiver->uuid;
            $receiverWorkflow->apps_id = $leadReceiver->apps_id;
            $receiverWorkflow->companies_id = $leadReceiver->companies_id;
            $receiverWorkflow->action_id = $action->id;
            $receiverWorkflow->users_id = $leadReceiver->users_id;
            $receiverWorkflow->name = $leadReceiver->name;
            $receiverWorkflow->description = 'Create Leads From Receiver';
            $receiverWorkflow->configuration = [
                'receiver_id' => $leadReceiver->id,
            ];
            $receiverWorkflow->is_active = true;
            $receiverWorkflow->run_async = true;
            $receiverWorkflow->saveOrFail();
        }
    }
}
