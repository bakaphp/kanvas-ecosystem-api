<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Guild\Customers\Services\ConsentGuard;

final class EligibilityResolver
{
    /**
     * Asked when a send is requested and again when it is dispatched: a stop request that arrives
     * while a delivery is queued has to win.
     *
     * @return string|null why this recipient may not be messaged on the channel, or null when they may
     */
    public function reasonNotToContact(Recipient $recipient, ChannelTypeEnum $channel, string $address): ?string
    {
        if ($recipient->people === null) {
            return null;
        }

        if (ConsentGuard::isPersonDoNotContact($recipient->people)) {
            return 'do_not_contact';
        }

        $isOptedOut = ConsentGuard::isDestinationOptedOut(
            $recipient->people,
            $address,
            $channel === ChannelTypeEnum::EMAIL
        );

        return $isOptedOut ? 'destination_opted_out' : null;
    }
}
