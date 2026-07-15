<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\CustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

class DisconnectSlackAgentAction
{
    public function __construct(
        private readonly Agent $agent,
        private readonly Apps $app,
    ) {
    }

    public function execute(): bool
    {
        $receiverId = $this->agent->get(CustomFieldEnum::RECEIVER_ID->value);

        if ($receiverId !== null) {
            /** @var ReceiverWebhook $receiver */
            $receiver = ReceiverWebhook::getById((int) $receiverId, $this->app);
            $receiver->is_active = false;
            $receiver->saveOrFail();
        }

        $this->agent->del(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value);

        return true;
    }
}
