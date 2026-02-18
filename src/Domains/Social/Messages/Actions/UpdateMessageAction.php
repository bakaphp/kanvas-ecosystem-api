<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;

class UpdateMessageAction
{
    public function __construct(
        protected readonly Message $message,
        protected readonly MessageInput $data,
    ) {
    }

    public function execute(): Message
    {
        return DB::connection('social')->transaction(function () {
            $this->message->message = $this->data->message;
            $this->message->message_types_id = $this->data->type->getId();
            $this->message->is_public = (int) $this->data->is_public;
            $this->message->saveOrFail();

            if (count($this->data->tags)) {
                $this->message->syncTags($this->data->tags);
            }

            if (count($this->data->categories)) {
                $this->message->syncCategories($this->data->categories);
            }

            $this->message->clearLightHouseCache();

            return $this->message;
        });
    }
}
