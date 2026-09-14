<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Enums;

enum ConversationTypeEnum: string
{
    case DIRECT = 'direct';
    case GROUP = 'group';
    case NEWSLETTER = 'newsletter';

    public static function fromJid(?string $jid): self
    {
        return match (true) {
            $jid !== null && str_contains($jid, '@g.us') => self::GROUP,
            $jid !== null && str_contains($jid, '@newsletter') => self::NEWSLETTER,
            default => self::DIRECT,
        };
    }
}
