<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Messages;

use Algolia\AlgoliaSearch\SearchClient;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Interactions\Models\Interactions;
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
        $user = auth()->user();

        if (! $user->isAppOwner()) {
            return Message::fromCompany($user->getCurrentCompany());
        }

        return Message::query();
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

    public function getGroupByDate(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ) {
        return Message::select(
            DB::raw('*, CASE
                WHEN created_at >= CURDATE() THEN "Today"
                WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN "Previous 7 Days"
                WHEN created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN "Previous 30 Days"
                ELSE DATE_FORMAT(created_at, "%M %Y")
            END as additional_field')
        );
    }

    public function searchSuggestions(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): array {
        $client = SearchClient::create(
            config('scout.algolia.id'),
            config('scout.algolia.secret')
        );

        $app = app(Apps::class);
        $suggestionIndex = AppEnum::MESSAGE_SEARCH_SUGGESTION_INDEX->value;
        if (! $app->get($suggestionIndex)) {
            return ['error' => 'No index for message suggestion configure in your app'];
        }

        $index = $client->initIndex($app->get($suggestionIndex));

        $results = $index->search($args['search'], [
            'hitsPerPage' => 15,
            'attributesToRetrieve' => ['name', 'description'],
        ]);

        return $results['hits'];
    }

    public function likedMessagesByUser(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $userId = (int) $args['id'];
        $app = app(Apps::class);

        $like = Interactions::getByName('like', $app);

        return Message::join('users_interactions', 'messages.id', '=', 'users_interactions.entity_id')
            ->where('users_interactions.entity_namespace', '=', Message::class)
            ->where('users_interactions.interactions_id', '=', $like->getId())
            ->where('users_interactions.users_id', '=', $userId)
            ->where('users_interactions.is_deleted', '=', 0)
            ->where('messages.is_deleted', '=', 0)
            ->where('messages.apps_id', '=', $app->getId())
            ->select('messages.*');
    }
}
