<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class MessagesRepository
{
    /**
     * getById
     */
    public static function getUserAllMessagesTags(Users $user, Companies $company, AppInterface $app, int $messageTypesId): array
    {
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
}
