<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Messages;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Social\Messages\Models\Message;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class MessageBuilder
{
    public function getAll(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        /**
         * @psalm-suppress MixedReturnStatement
         */
        return Message::fromApp();
    }

    public function getChannelMessages(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        return Message::fromApp()->whereHas('channels', function ($query) use ($args) {
            $query->where('channels.uuid', $args['channel_uuid']);
        })
        ->when(! auth()->user()->isAdmin(), function ($query) {
            $query->where('companies_id', auth()->user()->currentCompanyId());
        })
        ->select('messages.*');
    }
}
