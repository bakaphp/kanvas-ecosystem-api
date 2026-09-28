<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Channels;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Repositories\ChannelRepository;
use Kanvas\Users\Models\Users;

class GetSocialChannelsBuilder
{
    public function getChannels(): Builder
    {
        /** @var Users $user */
        $user = auth()->user();

        return ChannelRepository::getByIdBuilder(
            $user,
            app(Apps::class),
            $user->getCurrentCompany()
        );
    }
}
