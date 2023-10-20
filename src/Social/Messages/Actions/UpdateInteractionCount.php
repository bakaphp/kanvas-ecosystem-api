<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class UpdateInteractionCount
{
    public function __construct(
        protected Message $message
    ) {
    }

    public function execute()
    {
        $this->message->total_liked = UserMessage::where('messages_id', $this->message->id)
            ->where('is_liked', true)
            ->count();
        $this->message->total_saved = UserMessage::where('messages_id', $this->message->id)
            ->where('is_saved', true)
            ->count();
        $this->message->total_shared = UserMessage::where('messages_id', $this->message->id)
            ->where('is_shared', true)
            ->count();
        $this->message->save();
    }
}
