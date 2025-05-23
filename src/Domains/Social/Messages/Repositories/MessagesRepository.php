<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class MessagesRepository
{
    public static function getUserAllMessagesTags(
        Users $user,
        Companies $company,
        AppInterface $app,
        int $messageTypesId
    ): array {
        $userPostsTags = [];
        $query = Message::fromApp($app)
            ->where('users_id', $user->getId())
            ->where('companies_id', $company->getId())
            ->where('message_types_id', $messageTypesId)
            ->where('is_deleted', 0);

        $cursor = $query->cursor();

        foreach ($cursor as $message) {
            $userPostsTags = array_merge($message->tags()->pluck('slug')->toArray(), $userPostsTags);
        }

        return array_values(array_unique($userPostsTags));
    }

    public static function getMostPopularMesssageByTotalLikes(Apps $app, MessageType $messageType): Message| null
    {
        $popularMessage = Message::query()
            ->where('apps_id', $app->getId())
            ->where('message_types_id', $messageType->getId())
            ->whereRaw('YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)')
            ->orderBy('total_liked', 'DESC')
            ->limit(1)
            ->first();

        return $popularMessage ?? null;
    }
}
