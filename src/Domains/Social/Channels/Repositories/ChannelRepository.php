<?php

declare(strict_types=1);

namespace Kanvas\Social\Channels\Repositories;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class ChannelRepository
{
    public static function getById(
        int $id,
        Users $user,
        ?AppInterface $app = null,
        ?CompanyInterface $company = null
    ): Channel {
        return self::getByIdBuilder($user, $app, $company)->findOrFail($id);
    }

    public static function getByIdBuilder(
        Users $user,
        ?AppInterface $app = null,
        ?CompanyInterface $company = null
    ): Builder {
        $app = $app ?? app(Apps::class);
        $databaseSocial = config('database.connections.social.database', 'social');
        $isMember = fn (QueryBuilder $query): QueryBuilder => $query
            ->from($databaseSocial . '.channel_users')
            ->whereColumn('channel_users.channel_id', 'channels.id')
            ->where('channel_users.users_id', $user->getId());

        $builder = Channel::query()
            ->select('channels.*')
            ->where('channels.apps_id', $app->getId())
            ->where('channels.is_deleted', 0);

        if (! $user->isAdmin()) {
            return $builder->whereExists($isMember);
        }

        $companyId = ($company ?? $user->getCurrentCompany())->getId();

        return $builder->where(
            fn (Builder $query): Builder => $query
                ->where('channels.companies_id', $companyId)
                ->orWhereExists($isMember)
        );
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
