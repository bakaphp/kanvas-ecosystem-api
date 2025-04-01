<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class MessagesRepository
{
    public static function getcurrentMonthCreationCount(Apps $app, Users $user, MessageType $messageType): int
    {
        $messageCountOnCurrentMonth = Message::query()
            ->selectRaw('COUNT(id) as count')
            ->where('apps_id', $app->getId())
            ->where('users_id', $user->getId())
            ->where('message_types_id', $messageType->getId())
            ->whereRaw('MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())')
            ->first();
        return $messageCountOnCurrentMonth->count;
    }
}
