<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Concerns;

use Kanvas\Social\Messages\Models\Message;

/**
 * For lead-only workflow activities (CRM note pushes): an engagement message whose engagement
 * references a quote, invoice, ... has nothing to push. A benign skip, not a failure.
 */
trait SkipsNonLeadEngagements
{
    protected function skipNonLeadEngagement(Message $message): ?array
    {
        if ($message->engagement?->hasNonLeadReference() !== true) {
            return null;
        }

        return [
            'message_id' => $message->getId(),
            'message' => 'Engagement references a ' . $message->engagement->entity_type . ', not a lead; skipping.',
        ];
    }
}
