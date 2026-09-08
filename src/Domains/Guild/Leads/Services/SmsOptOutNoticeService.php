<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Services;

use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Enums\MessageSenderTypeEnum;
use Kanvas\Social\Messages\Models\Message;

/**
 * The first outbound SMS of a conversation must carry an opt-out clause, whether a human
 * or an agent wrote it. Agents are told to include one in their prompt, but a prompt is
 * not a guarantee — this appends it when the copy came back without one.
 */
class SmsOptOutNoticeService
{
    public const string NOTICE = 'Reply STOP to opt out.';

    private const array NOTICE_NEEDLES = [
        'reply stop',
        'opt out',
        'opt-out',
    ];

    public static function appendTo(string $body): string
    {
        $body = trim($body);

        if (self::hasNotice($body)) {
            return $body;
        }

        return $body === '' ? self::NOTICE : $body . "\n\n" . self::NOTICE;
    }

    public static function hasNotice(string $body): bool
    {
        foreach (self::NOTICE_NEEDLES as $needle) {
            if (stripos($body, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * The clause rides on the first message WE send, whether the customer opened the
     * conversation or we did — so this counts outbound history only, not channel history.
     * `$exclude` is the outbound row when it is already persisted and attached (the human
     * lane); the reply lane has not created its row yet and passes nothing.
     */
    public static function isFirstOutboundMessage(Channel $channel, ?Message $exclude = null): bool
    {
        $query = $channel->messages()
            ->where('messages.is_deleted', 0)
            ->whereIn('messages.sender_type', [
                MessageSenderTypeEnum::USER->value,
                MessageSenderTypeEnum::AGENT->value,
            ]);

        if ($exclude !== null) {
            $query->where('messages.id', '!=', $exclude->getId());
        }

        return $query->doesntExist();
    }
}
