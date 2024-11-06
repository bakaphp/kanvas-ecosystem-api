<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Messages;

use Algolia\AlgoliaSearch\SearchClient;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\AppKey;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Jobs\UserInteractionJob;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
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
        $app = app(Apps::class);

        $viewingOneMessage = isset($args['where']['column']) && ($args['where']['column'] === 'id' || $args['where']['column'] === 'uuid') && isset($args['where']['value']);
        //if enable home-view interaction , remove once , moved to getUserFeed
        if ($app->get('TEMP_HOME_VIEW_EVENT') && ! app(AppKey::class) && ! $viewingOneMessage) {
            UserInteractionJob::dispatch(
                $app,
                $user,
                $app,
                InteractionEnum::VIEW_HOME_PAGE->getValue()
            );
        } elseif ($viewingOneMessage) {
            UserInteractionJob::dispatch(
                $app,
                $user,
                new Message(['id' => $args['where']['value']]),
                InteractionEnum::VIEW_ITEM->getValue()
            );
        }

        if (! $user->isAppOwner()) {
            return Message::fromCompany($user->getCurrentCompany());
        }

        return Message::query();
    }

    public function getUserFeed(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $app = app(Apps::class);

        //generate home-view interaction
        UserInteractionJob::dispatch(
            $app,
            $user,
            $app,
            InteractionEnum::VIEW_HOME_PAGE->getValue()
        );

        return UserMessage::getUserFeed($user, $app);
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
