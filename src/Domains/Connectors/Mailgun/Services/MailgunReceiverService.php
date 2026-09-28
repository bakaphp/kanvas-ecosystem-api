<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Services;

use Kanvas\Connectors\Mailgun\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * The agent owns its receiver: one mailbox per agent means one forward URL per agent, and the
 * Mailgun route points at it forever. Reconnecting must land on the same row or every route the
 * customer already has forwards into a dead endpoint — hence the lookup through a custom field on
 * the agent rather than the usual firstOrCreate(apps_id, companies_id, action_id) composite.
 */
class MailgunReceiverService
{
    public function forAgent(Agent $agent): ReceiverWebhook
    {
        $receiverId = $agent->get(CustomFieldEnum::RECEIVER_ID->value);

        if ($receiverId !== null) {
            /** @var ReceiverWebhook $receiver */
            $receiver = ReceiverWebhook::getById((int) $receiverId, $agent->app);

            return $receiver;
        }

        $action = WorkflowAction::where('model_name', AgentInboxWebhookJob::class)->firstOrFail();

        $receiver = new ReceiverWebhook();
        $receiver->apps_id = $agent->app->getId();
        $receiver->companies_id = $agent->company->getId();
        // ProcessWebhookJob logs every inbound call in as receiver->user, so the agent's own user is
        // the right actor — not whoever happened to provision the mailbox.
        $receiver->users_id = $agent->user->getId();
        $receiver->action_id = $action->getId();
        $receiver->name = 'Email — ' . $agent->name;
        $receiver->description = 'Inbound email for agent ' . $agent->name;
        $receiver->configuration = [
            ReceiverConfigurationEnum::AGENT_ID->value => $agent->getId(),
            ReceiverConfigurationEnum::CAPTURE_FILES->value => true,
        ];
        $receiver->is_active = true;
        $receiver->run_async = true;
        $receiver->saveOrFail();

        $agent->set(CustomFieldEnum::RECEIVER_ID->value, $receiver->getId());

        return $receiver;
    }
}
