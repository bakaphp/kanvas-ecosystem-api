<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Observers;

use Kanvas\Social\Messages\Models\UserMessageActivity;

class UserMessageActivityObserver
{
    /**
     * created
     */
    public function created(UserMessageActivity $activity): void
    {
        $count = UserMessageActivity::where('type', $activity->type)
                                    ->where('user_messages_id', $activity->user_messages_id)
                                    ->count();
        $notes = [
            'notes' => $activity->userMessage->notes,
            'message_activity_count' => $count,
            'message_activity_username' => $activity->username,
            'message_type_activity' => $activity->type,
            'message_activity_text' => $activity->text,
        ];
        $userMessage = $activity->userMessage;
        $userMessage->activities = json_encode($notes);
        $userMessage->saveOrFail();
    }
}
