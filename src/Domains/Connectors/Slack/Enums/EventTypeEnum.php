<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Slack\Enums;

enum EventTypeEnum: string
{
    case URL_VERIFICATION = 'url_verification';
    case APP_MENTION = 'app_mention';
    case MESSAGE = 'message';
    case CHANNEL_CREATED = 'channel_created';
    case APP_UNINSTALLED = 'app_uninstalled';
    case TOKENS_REVOKED = 'tokens_revoked';

    /**
     * `message` subtypes that carry a human utterance. Everything else on the message event — joins,
     * leaves, topic changes, edits, deletions, pinned items — is channel bookkeeping, and ingesting it
     * would fill the agent's history with "X has joined the channel".
     */
    private const array UTTERANCE_SUBTYPES = ['file_share', 'thread_broadcast'];

    public static function isLifecycle(?string $type): bool
    {
        return in_array($type, [self::APP_UNINSTALLED->value, self::TOKENS_REVOKED->value], true);
    }

    public static function isUtterance(array $event): bool
    {
        $subtype = $event['subtype'] ?? null;

        return $subtype === null || in_array($subtype, self::UTTERANCE_SUBTYPES, true);
    }
}
