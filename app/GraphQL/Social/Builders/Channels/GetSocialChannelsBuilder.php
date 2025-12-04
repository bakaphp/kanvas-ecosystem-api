<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Channels;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Channels\Repositories\ChannelRepository;

class GetSocialChannelsBuilder
{
    public function getChannels(): Builder
    {
        //return ChannelRepository::getByIdBuilder(auth()->user());
        $user = auth()->user();

        $databaseSocial = config('database.connections.social.database', 'social');
        $builder = Channel::join($databaseSocial . '.channel_users', 'channel_users.channel_id', '=', 'channels.id')
           // ->where('channel_users.users_id', $user->getId())
            ->when(! $user->isAdmin(), function (Builder $query) use ($user) {
                $query->where('channel_users.users_id', $user->getId());
            })
            ->where('channels.is_deleted', 0);

        return $builder;
    }
}
