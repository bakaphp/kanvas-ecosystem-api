<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

use Kanvas\Connectors\WaSender\Traits\ReadsReceiverConfigurationTrait;
use Override;

/**
 * 1:1 behaviour on the receiver. The mode decides lead-vs-assistant for the whole connection;
 * the allow-list grants assistant treatment to specific counterparties even in `lead` mode, so
 * the owner texting their own agent does not become a Warm lead while customers still do.
 */
enum DirectConfigEnum: string
{
    use ReadsReceiverConfigurationTrait;

    case DIRECT_CONVERSATION_MODE = 'direct_conversation_mode';
    case ASSISTANT_CONTACT_JIDS = 'assistant_contact_jids';
    case DIRECT_REPLY_MODE = 'direct_reply_mode';

    #[Override]
    public function default(): mixed
    {
        return match ($this) {
            self::DIRECT_CONVERSATION_MODE => DirectConversationModeEnum::LEAD->value,
            self::ASSISTANT_CONTACT_JIDS => [],
            self::DIRECT_REPLY_MODE => GroupReplyModeEnum::ALWAYS->value,
        };
    }
}
