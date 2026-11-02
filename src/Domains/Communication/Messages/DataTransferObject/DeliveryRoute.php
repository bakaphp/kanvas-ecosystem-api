<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;

/**
 * The connection and address one channel of a request resolved to. Either may be missing — that is
 * recorded as a failed delivery, so the caller sees why a channel did not go out.
 */
final readonly class DeliveryRoute
{
    public function __construct(
        public ChannelTypeEnum $channel,
        public ?Connection $connection,
        public ?string $address
    ) {
    }
}
