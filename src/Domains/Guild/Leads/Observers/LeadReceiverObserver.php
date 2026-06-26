<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Observers;

use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverJob;
use Kanvas\Guild\Leads\Jobs\CreateLeadsFromReceiverWithConfirmationJob;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Rules\Models\Action;

class LeadReceiverObserver
{
    public function created(LeadReceiver $leadReceiver): void
    {
        // The confirmation-capable job is a strict superset of the base receiver; prefer it so the
        // submitter confirmation (gated by configuration.send_confirmation) is available, and fall
        // back to the base job when its Action row hasn't been synced yet.
        $action = Action::where('model_name', CreateLeadsFromReceiverWithConfirmationJob::class)->first()
            ?? Action::where('model_name', CreateLeadsFromReceiverJob::class)->first();

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
