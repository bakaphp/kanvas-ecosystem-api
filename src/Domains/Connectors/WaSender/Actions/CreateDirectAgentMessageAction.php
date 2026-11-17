<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\DataTransferObject\InboundMessage;
use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConfigEnum;
use Kanvas\Connectors\WaSender\Enums\DirectConversationModeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Files one assistant-mode 1:1 message: the group flow with a single speaker. No Lead, no lead
 * source, no stakeholder notification — the conversation entity is the Channel, the counterparty
 * resolves to People, and the burst runs the agent once with `should_reply` on by default.
 */
class CreateDirectAgentMessageAction extends BaseInboundMessageAction
{
    /**
     * Whether this 1:1 conversation gets assistant treatment instead of the lead flow. Checked on
     * the conversation, not the sender — an outbound message to an assistant contact must divert
     * too, or our own replies would open a lead for the counterparty.
     */
    public static function appliesTo(ReceiverWebhook $receiver, InboundMessage $inbound): bool
    {
        $mode = DirectConversationModeEnum::tryFromValue(
            DirectConfigEnum::DIRECT_CONVERSATION_MODE->get($receiver)
        );

        if ($mode === DirectConversationModeEnum::ASSISTANT) {
            return true;
        }

        // Bare forms on both sides: an operator may allow-list a full JID, a phone, or a lid.
        $allowed = array_map(
            InboundMessage::toBareId(...),
            DirectConfigEnum::ASSISTANT_CONTACT_JIDS->getList($receiver)
        );

        if ($allowed === []) {
            return false;
        }

        $candidates = array_filter([
            InboundMessage::toBareId($inbound->conversationJid),
            $inbound->senderPhone,
            $inbound->senderLid,
        ]);

        return array_intersect($candidates, $allowed) !== [];
    }

    /**
     * Our own side of the chat is nobody's People.
     */
    #[Override]
    protected function speaker(): ?People
    {
        return $this->inbound->isFromMe ? null : parent::speaker();
    }

    /**
     * A 1:1 is addressed by definition, so both windows are the short one — the sender expects an
     * answer, not a 30-second wait.
     */
    #[Override]
    protected function chainIdleSeconds(): int
    {
        return BurstConfigEnum::BURST_MENTION_IDLE_SECONDS->getInt($this->receiver);
    }

    #[Override]
    protected function closeIdleSeconds(): int
    {
        return $this->chainIdleSeconds();
    }
}
