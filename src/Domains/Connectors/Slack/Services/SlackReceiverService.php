<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\CustomFieldEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * The agent owns its receiver: one Slack app per agent means one request URL per agent.
 *
 * Every other connector does ReceiverWebhook::firstOrCreate(apps_id, companies_id, action_id) —
 * which here would hand agent #2 in a company agent #1's receiver, and therefore agent #1's Slack
 * app. So the lookup goes through a custom field on the agent instead of a composite key.
 *
 * The receiver is created on FIRST manifest generation, not on connect: the manifest has to carry
 * the request URL, which means the receiver has to exist before the customer has any tokens to give
 * us. It then outlives disconnects — the customer's Slack app points at that URL forever, so
 * reconnecting must land on the same row.
 */
class SlackReceiverService
{
    public function __construct(
        private readonly Apps $app,
        private readonly CompanyInterface $company,
        private readonly Users $user,
    ) {
    }

    public function forAgent(Agent $agent): ReceiverWebhook
    {
        $receiverId = $agent->get(CustomFieldEnum::RECEIVER_ID->value);

        if ($receiverId !== null) {
            /** @var ReceiverWebhook $receiver */
            $receiver = ReceiverWebhook::getById((int) $receiverId, $this->app);

            return $receiver;
        }

        $action = WorkflowAction::where('model_name', ProcessSlackWebhookJob::class)->firstOrFail();

        $receiver = new ReceiverWebhook();
        $receiver->apps_id = $this->app->getId();
        $receiver->companies_id = $this->company->getId();
        $receiver->users_id = $this->user->getId();
        $receiver->action_id = $action->getId();
        $receiver->name = 'Slack — ' . $agent->name;
        $receiver->description = 'Inbound Slack events for agent ' . $agent->name;
        $receiver->configuration = [ConfigurationEnum::AGENT_ID->value => $agent->getId()];
        $receiver->is_active = true;
        // The url_verification challenge is answered by the job's handshake hook, so the receiver
        // itself never has to run inline.
        $receiver->run_async = true;
        $receiver->saveOrFail();

        $agent->set(CustomFieldEnum::RECEIVER_ID->value, $receiver->getId());

        return $receiver;
    }
}
