<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Channels;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Channels\Repositories\ChannelRepository;

class GetSocialChannelsBuilder
{
    public function getChannels(): Builder
    {
        return ChannelRepository::getByIdBuilder(auth()->user());
    }
}
