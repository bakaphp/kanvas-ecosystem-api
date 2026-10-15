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
    case MEMBER_JOINED_CHANNEL = 'member_joined_channel';

    public static function isLifecycle(?string $type): bool
    {
        return in_array($type, [self::APP_UNINSTALLED->value, self::TOKENS_REVOKED->value], true);
    }

    /**
     * An edit or a deletion corrects a message already on record; it is never a new request.
     */
    public static function isCorrection(?string $subtype): bool
    {
        return in_array($subtype, [self::MESSAGE_CHANGED->value, self::MESSAGE_DELETED->value], true);
    }

    /**
     * Message subtypes that are room housekeeping, not conversation.
     */
    public static function isNoise(?string $subtype): bool
    {
        return in_array($subtype, [
            'channel_join',
            'channel_leave',
            'channel_topic',
            'channel_purpose',
            'channel_name',
            'channel_archive',
            'channel_unarchive',
        ], true);
    }
}
