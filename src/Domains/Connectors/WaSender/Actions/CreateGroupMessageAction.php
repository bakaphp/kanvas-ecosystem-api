<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Actions;

use Kanvas\Connectors\WaSender\Enums\BurstConfigEnum;
use Kanvas\Connectors\WaSender\Enums\GroupConfigEnum;
use Kanvas\Connectors\WaSender\Services\GroupMentionService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;
use Override;

/**
 * Files one inbound group message. Silent by design: no Lead, no lead source, no receiver, no
 * stakeholder notification. The conversation entity is the Channel — a room belongs to no single
 * person — and each speaker resolves to their own People so the agent can tell voices apart.
 *
 * Returns null when the group is not allow-listed, which is the normal case for the dozens of
 * groups a company phone sits in.
 */
class CreateGroupMessageAction extends BaseInboundMessageAction
{
    #[Override]
    public function execute(): ?Message
    {
        if (! $this->isAllowed()) {
            return null;
        }

        $message = parent::execute();

        // Our own message is the one moment WhatsApp discloses our lid.
        if ($message !== null) {
            new GroupMentionService($this->receiver, $this->channel)->rememberOwnLid($this->inbound);
        }

        return $message;
    }

    #[Override]
    protected function storedText(?string $text, ?People $speaker): ?string
    {
        $name = $this->inbound->pushName ?? $speaker?->getName();

        if ($text === null || $name === null || trim($name) === '') {
            return $text;
        }

        return trim($name) . ': ' . $text;
    }

    #[Override]
    protected function shapePayload(): array
    {
        return [
            'group_jid' => $this->inbound->conversationJid,
            'mentioned_jids' => $this->inbound->mentionedJids,
        ];
    }

    #[Override]
    protected function chainIdleSeconds(): int
    {
        return BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver);
    }

    /**
     * A mention shortens the wait instead of closing the burst outright — `@agent look at this
     * <3 photos>` must stay one turn, but the person asking should not wait the full idle window.
     */
    #[Override]
    protected function closeIdleSeconds(): int
    {
        return $this->inbound->mentionedJids !== [] || $this->inbound->quotedMessageId !== null
            ? BurstConfigEnum::BURST_MENTION_IDLE_SECONDS->getInt($this->receiver)
            : BurstConfigEnum::BURST_IDLE_SECONDS->getInt($this->receiver);
    }

    private function isAllowed(): bool
    {
        return in_array(
            $this->inbound->conversationJid,
            GroupConfigEnum::ALLOWED_GROUP_JIDS->getList($this->receiver),
            true
        );
    }
}
