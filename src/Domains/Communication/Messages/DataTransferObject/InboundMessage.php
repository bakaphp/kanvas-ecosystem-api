<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Customers\Enums\ConsentSignalEnum;

/**
 * What a provider's webhook job parsed out of one inbound payload, before anything is filed. The
 * provider knows its payload; everything after this is the same for every medium.
 */
final readonly class InboundMessage
{
    /**
     * @param string $inboundIdentity the identity the vendor delivered to — our number, our address —
     *                                which is what routes the message to a connection
     * @param string $from the counterpart's address
     * @param string $conversationSlug the Social channel the conversation is filed in, one per
     *                                 counterpart and provider
     * @param string $messageVerb the message type it is filed as
     * @param string|null $messageSlug stable per vendor message, so a redelivery updates rather than
     *                                 files a second copy
     * @param bool $fromUs a message the vendor reports from our own side — announced, never answered
     * @param ConsentSignalEnum|null $consentSignal the vendor's own classification, when it makes one;
     *                                              otherwise the shared keyword matcher decides
     * @param list<InboundAttachment> $attachments
     * @param array<string, mixed> $personCustomFields
     * @param list<string> $personTags
     * @param bool $reuseKnownPerson keep a person already on file as they are (a test session that
     *                               stands in for someone else's number)
     */
    public function __construct(
        public string $provider,
        public ChannelTypeEnum $channel,
        public string $inboundIdentity,
        public string $from,
        public ?string $providerMessageId = null,
        public string $conversationSlug = '',
        public string $messageVerb = '',
        public ?string $messageSlug = null,
        public ?string $text = null,
        public bool $fromUs = false,
        public array $raw = [],
        public ?ConsentSignalEnum $consentSignal = null,
        public array $attachments = [],
        public array $personCustomFields = [],
        public array $personTags = [],
        public bool $reuseKnownPerson = false
    ) {
    }
}
