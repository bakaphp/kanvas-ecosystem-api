<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Connectors\Mailgun\Actions\SendAgentEmailAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Enums\ChannelCategoryEnum;
use Kanvas\Social\Messages\Models\Message;

/**
 * Approves a locked agent draft and ships it through its channel, then unlocks it.
 *
 * Routes by the outbound message-type verb (not communicationChannel, which 'email' shares between
 * Mailgun + Microsoft). Only Mailgun is wired in this first cut.
 */
class ApproveAgentMessageAction
{
    public function __construct(
        protected Message $message,
        protected ?string $editedText = null
    ) {
    }

    public function execute(): Message
    {
        if (! $this->message->isLocked()) {
            throw new ValidationException('Message is not pending approval');
        }

        if ($this->editedText !== null && $this->editedText !== '') {
            $this->message->addMessage([
                'content' => $this->editedText,
                'raw_data' => $this->editedText,
            ]);
        }

        $verb = $this->message->messageType?->verb;

        match ($verb) {
            ChannelCategoryEnum::MAILGUN->value => new SendAgentEmailAction($this->message)->execute(),
            default => throw new ValidationException(
                'Approval send is not supported for message type: ' . (string) $verb
            ),
        };

        $this->message->setUnlock();

        return $this->message->refresh();
    }
}
