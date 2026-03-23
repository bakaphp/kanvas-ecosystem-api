<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Repositories\MessagesRepository;

class MarkLeadMessagesAsRespondedAction
{
    public function __construct(
        protected Lead $lead,
        protected Message $responseMessage,
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
                $message->response = true;
                $message->response_message_id = $this->responseMessage->getId();
                $message->saveOrFail();
                $count++;
            }

            return $count;
        });
    }
}
