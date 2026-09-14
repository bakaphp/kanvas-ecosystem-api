<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Enums;

enum EventTypeEnum: string
{
    case URL_VERIFICATION = 'url_verification';
    case APP_MENTION = 'app_mention';
    case MESSAGE = 'message';
    case APP_UNINSTALLED = 'app_uninstalled';
    case TOKENS_REVOKED = 'tokens_revoked';
    case CHANNEL_CREATED = 'channel_created';
    case MESSAGE_CHANGED = 'message_changed';
    case MESSAGE_DELETED = 'message_deleted';

    public static function isLifecycle(?string $type): bool
    {
        return in_array($type, [self::APP_UNINSTALLED->value, self::TOKENS_REVOKED->value], true);
    }
}
