<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Delivery\DataTransferObject;

use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\Social\Messages\Models\Message;

final class DocumentDelivery
{
    public function __construct(
        public readonly Engagement $engagement,
        public readonly string $link,
        public readonly DeliveryTarget $target,
        public readonly Message $message,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolResult(): array
    {
        return [
            'engagement_uuid' => $this->engagement->uuid,
            'link' => $this->link,
            'channel' => $this->target->channel->value,
            'sent_to' => $this->target->destination,
        ];
    }
}
