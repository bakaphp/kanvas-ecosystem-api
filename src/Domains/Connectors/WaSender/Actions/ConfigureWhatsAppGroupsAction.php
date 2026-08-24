<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Enums\ConnectionFieldEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupReplyModeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Workflow\Models\ReceiverWebhook;

/**
 * Writes the group block onto the agent's WhatsApp receiver.
 *
 * Everything here is opt-in and reversible: an empty allow-list ingests no group at all, and the
 * reply mode governs only whether the agent speaks — a group it listens to silently is still
 * filed, still processed, and still fires its workflow.
 */
class ConfigureWhatsAppGroupsAction
{
    /**
     * @param list<string>|null $allowedGroupJids
     */
    public function __construct(
        protected readonly Agent $agent,
        protected readonly ?array $allowedGroupJids = null,
        protected readonly ?GroupReplyModeEnum $replyMode = null,
        protected readonly ?int $groupAgentId = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $receiver = $this->receiver();
        $configuration = $receiver->configuration ?? [];

        if ($this->allowedGroupJids !== null) {
            $configuration[GroupConfigEnum::ALLOWED_GROUP_JIDS->value] = $this->normalizeJids($this->allowedGroupJids);
        }

        if ($this->replyMode !== null) {
            $configuration[GroupConfigEnum::GROUP_REPLY_MODE->value] = $this->replyMode->value;
        }

        // Defaults to the agent that owns the connection, so the common case needs no argument.
        $configuration[GroupConfigEnum::GROUP_AGENT_ID->value] = $this->groupAgentId
            ?? $configuration[GroupConfigEnum::GROUP_AGENT_ID->value]
            ?? $this->agent->getId();

        $receiver->configuration = $configuration;
        $receiver->saveOrFail();

        return [
            'allowed_group_jids' => GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($receiver),
            'reply_mode' => (string) GroupConfigEnum::GROUP_REPLY_MODE->get($receiver),
            'agent_id' => $configuration[GroupConfigEnum::GROUP_AGENT_ID->value],
        ];
    }

    /**
     * @param list<string> $jids
     *
     * @return list<string>
     */
    private function normalizeJids(array $jids): array
    {
        $normalized = [];

        foreach ($jids as $jid) {
            $jid = trim($jid);

            if ($jid === '') {
                continue;
            }

            // A bare phone would silently allow-list nothing, since a group thread is only ever
            // addressed by its @g.us JID.
            if (! str_contains($jid, '@g.us')) {
                throw new ValidationException($jid . ' is not a WhatsApp group JID');
            }

            $normalized[] = $jid;
        }

        return array_values(array_unique($normalized));
    }

    private function receiver(): ReceiverWebhook
    {
        $receiverId = (int) $this->agent->get(ConnectionFieldEnum::RECEIVER_ID->value);

        $receiver = $receiverId > 0 ? ReceiverWebhook::find($receiverId) : null;

        if ($receiver === null) {
            throw new ValidationException('This agent has no WhatsApp connection to configure groups on');
        }

        return $receiver;
    }
}
