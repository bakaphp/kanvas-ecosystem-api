<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;

/**
 * Rejects a locked agent draft — the reply is discarded (soft-deleted) and never sent.
 */
class RejectAgentMessageAction
{
    public function __construct(
        protected Message $message
    ) {
    }

    public function execute(): bool
    {
        if (! $this->message->isLocked()) {
            throw new ValidationException('Message is not pending approval');
        }

        return $this->message->softDelete();
    }
}
