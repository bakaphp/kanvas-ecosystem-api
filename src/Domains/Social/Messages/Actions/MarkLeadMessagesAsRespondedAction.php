<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessagesRepository;

class MarkLeadMessagesAsRespondedAction
{
    /**
     * @param Message|null $answered the inbound the response answers. With it, only what the
     *                               responder could have read is marked: messages up to that one,
     *                               the parts of its burst and the response itself. A text that
     *                               arrived while the answer was being written stays unanswered.
     *                               Without it every unanswered message of the lead is marked.
     */
    public function __construct(
        protected Lead $lead,
        protected Message $responseMessage,
        protected ?Message $answered = null,
    ) {
    }

    public function execute(): int
    {
        return DB::connection('social')->transaction(function () {
            $unrespondedMessages = MessagesRepository::getUnrespondedMessagesByLead(
                $this->lead->getId(),
                $this->lead->app
            );

            $count = 0;
            foreach ($unrespondedMessages as $message) {
                if (! $this->wasAnswered($message)) {
                    continue;
                }

                $message->is_un_response = true;
                $message->response_message_id = $this->responseMessage->getId();
                $message->saveOrFail();
                $count++;
            }

            return $count;
        });
    }

    private function wasAnswered(Message $message): bool
    {
        if ($this->answered === null) {
            return true;
        }

        return $message->getId() <= $this->answered->getId()
            || (int) $message->parent_id === $this->answered->getId()
            || $message->is($this->responseMessage);
    }
}
