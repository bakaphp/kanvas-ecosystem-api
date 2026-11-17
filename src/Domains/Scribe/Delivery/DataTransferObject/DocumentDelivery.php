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
            ...($this->leftTheFilesOut() ? [
                'pdf_attached' => false,
                'note' => 'The link was sent, but this channel could not carry the PDF. Say so: the '
                    . 'customer opens the document from the link.',
            ] : []),
        ];
    }

    /**
     * A vendor that cannot carry a file sends the text and reports it (`attachments_dropped`,
     * `attachments_truncated`) on the send, which is kept on the filed message.
     */
    private function leftTheFilesOut(): bool
    {
        $warnings = (array) ($this->message->message['raw_data']['warnings'] ?? []);

        return array_any(
            $warnings,
            fn (mixed $warning): bool => str_starts_with((string) $warning, 'attachments_')
        );
    }
}
