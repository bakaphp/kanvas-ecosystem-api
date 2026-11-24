<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;

/**
 * Where a conversation lives: which of our accounts the counterpart is talking to, and who they are
 * on that channel. A reply goes back through exactly this, never through the company default.
 */
final readonly class ConversationRef
{
    /**
     * @param string $accountKey our side — matches Connection::account_key
     * @param string $thread the counterpart's address on the channel
     * @param string $channelSlug the Social channel the conversation is filed under; the slug
     *                            predates this domain, so each provider keeps the one it always used
     * @param string $messageVerb the message type its messages are filed as
     */
    public function __construct(
        public ChannelTypeEnum $channel,
        public string $provider,
        public string $accountKey,
        public string $thread,
        public string $channelSlug,
        public string $messageVerb
    ) {
    }
}
