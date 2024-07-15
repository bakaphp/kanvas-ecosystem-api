<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Models\Channel;
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
            ->where('users_id', $user->getId());

        return $builder;
    }
}
