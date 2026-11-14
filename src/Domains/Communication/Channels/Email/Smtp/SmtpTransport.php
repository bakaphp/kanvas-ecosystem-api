<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Smtp;

use Baka\Support\Str;
use Illuminate\Support\Facades\Notification;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Contracts\Transport;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\ConversationRef;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\Social\Messages\Models\Message;
use Override;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Hands the composed email to the company's SMTP server, in this process: the delivery is already a
 * queued job, and its outcome has to be the server's answer, not "queued again".
 */
class SmtpTransport implements Transport
{
    public const string MESSAGE_VERB = 'email';

    #[Override]
    public function send(Delivery $delivery, Content $content): TransportResult
    {
        $notification = new EmailComposer($delivery, $content)->notification();

        // Fixed here, not left to the mailer: it is the only thing a later event from the host
        // (delivered, opened, bounced) carries back to this delivery.
        $domain = Str::after((string) $delivery->connection->account_key, '@');
        $messageId = bin2hex(random_bytes(16)) . '@' . ($domain !== '' ? $domain : 'kanvas.dev');
        $notification->setMessageId($messageId);

        try {
            Notification::route('mail', (string) $delivery->to_address)->notifyNow($notification);
        } catch (TransportExceptionInterface $exception) {
            return TransportResult::failed('rejected_by_provider', $exception->getMessage());
        }

        return TransportResult::accepted($messageId);
    }

    #[Override]
    public function lookup(Delivery $delivery): ?TransportResult
    {
        return null;
    }

    #[Override]
    public function conversation(Connection $connection, string $address): ConversationRef
    {
        return new ConversationRef(
            channel: ChannelTypeEnum::EMAIL,
            provider: SmtpCarrier::slug(),
            accountKey: $connection->account_key,
            thread: strtolower($address),
            channelSlug: SessionChannelService::createChannelSlug('email', strtolower($address)),
            messageVerb: self::MESSAGE_VERB
        );
    }

    /**
     * Nothing arrives over SMTP, so no filed message is ever one of its own.
     */
    #[Override]
    public function conversationOf(Message $message): ?ConversationRef
    {
        return null;
    }
}
