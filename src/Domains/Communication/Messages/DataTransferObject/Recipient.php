<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Customers\Models\People;

/**
 * Who a message is for. Communication speaks to a person, never to a lead: consent and contacts
 * live on the person, and a person with three leads is still one conversation. The address on each
 * channel is derived here, so no caller picks a phone number or an inbox.
 */
final readonly class Recipient
{
    /**
     * @param string|null $address set on a reply, where the address is the one the conversation
     *                             already uses rather than the person's first contact of that kind
     */
    private function __construct(
        public ?People $people,
        public ?string $address = null
    ) {
    }

    public static function of(People $people): self
    {
        return new self($people);
    }

    public static function inConversation(?People $people, string $address): self
    {
        return new self($people, $address);
    }

    public function addressOn(ChannelTypeEnum $channel): ?string
    {
        if ($this->address !== null) {
            return $this->address;
        }

        $contact = match (true) {
            $channel->isAddressedByPhone() => $this->people?->getCellPhones()->first(),
            $channel === ChannelTypeEnum::EMAIL => $this->people?->getEmails()->first(),
            default => null,
        };

        return $channel->normalizeAddress((string) $contact?->value);
    }
}
