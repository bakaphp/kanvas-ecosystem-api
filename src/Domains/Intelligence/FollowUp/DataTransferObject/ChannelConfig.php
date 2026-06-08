<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\DataTransferObject;

use Spatie\LaravelData\Data;

class ChannelConfig extends Data
{
    public function __construct(
        public readonly string $type,
        public readonly bool $enabled,
        // Templates.name scoped to app+company. Null = agent freewrites
        // (and for WhatsApp outside 24h, the channel is skipped).
        public readonly ?string $templateName = null,
    ) {
    }
}
