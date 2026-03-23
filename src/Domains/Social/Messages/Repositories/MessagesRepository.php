<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Repositories;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
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

    public static function getMostPopularMessageByTotalLikes(Apps $app, MessageType $messageType): Message| null
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

    public static function getUnrespondedMessagesByLead(int $leadId, AppInterface $app): Collection
    {
        return Message::fromApp($app)
            ->join('app_module_message', 'messages.id', '=', 'app_module_message.message_id')
            ->where('app_module_message.entity_id', $leadId)
            ->where('app_module_message.system_modules', Lead::class)
            ->where('messages.response', false)
            ->where('messages.is_deleted', 0)
            ->select('messages.*')
            ->get();
    }
}
