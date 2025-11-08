<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class ChannelRepository
{
    public static function getById(int $id, Users $user): Channel
    {
        return self::getByIdBuilder($user)->findOrFail($id);
    }

    public static function getByIdBuilder(Users $user): Builder
    {
        $databaseSocial = config('database.connections.social.database', 'social');
        $builder = Channel::join($databaseSocial . '.channel_users', 'channel_users.channel_id', '=', 'channels.id')
            ->where('channel_users.users_id', $user->getId())
            ->where('channels.is_deleted', 0);

        return $builder;
    }

    public static function getChannelMessagesByVerb(Channel $channel, string $verb): Builder
    {
        return Message::join('channel_messages', 'messages.id', '=', 'channel_messages.messages_id')
            ->join('message_types', 'messages.message_types_id', '=', 'message_types.id')
            ->where('channel_messages.channel_id', $channel->getId())
            ->where('message_types.verb', $verb)
            ->where('messages.is_deleted', 0)
            ->select('messages.*');
    }
}
