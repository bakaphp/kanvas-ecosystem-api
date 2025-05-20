<?php

declare(strict_types=1);

namespace App\GraphQL\Social\Builders\Messages;

use Algolia\AlgoliaSearch\SearchClient;
use Baka\Users\Contracts\UserInterface;
use Exception;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Recombee\Actions\GenerateRecommendCustomFeedAction;
use Kanvas\Connectors\Recombee\Actions\GenerateRecommendForYourFeedAction;
use Kanvas\Connectors\Recombee\Enums\ConfigurationEnum;
use Kanvas\Social\Enums\AppEnum;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Jobs\UserInteractionJob;
use Kanvas\Social\Interactions\Models\Interactions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Users\Enums\UserConfigEnum;
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

        $viewingOneMessage = isset($args['where']['column'], $args['where']['value']) && in_array($args['where']['column'], ['id', 'uuid', 'slug'], true);
        //if enable home-view interaction , remove once , moved to getUserFeed
        if ($app->get('TEMP_HOME_VIEW_EVENT') && $viewingOneMessage) {
            UserInteractionJob::dispatch(
                $app,
                $user,
                new Message(['id' => $args['where']['value']]),
                InteractionEnum::VIEW_ITEM->getValue()
            );
        }

        $query = Message::query();

        if (! empty($args['customFilters'])) {
            $query = $this->applyCustomFilters($query, $args, $user);
        }

        if (! empty($args['requiredTags'])) {
            $tagSlugs = $args['requiredTags'];

            foreach ($tagSlugs as $slug) {
                $query->whereHas('tags', function (Builder $q) use ($slug) {
                    $q->where('slug', $slug);
                });
            }

            $messageCacheTime = (int) $app->get('message_tags_cache_time');
            if ($messageCacheTime > 0) {
                $query->cacheFor($messageCacheTime);
            }
        }

        //Check in this condition if the message is an item and if then check if it has been bought by the current user via status=completed on Order
        if (! $user->isAppOwner()) {
            //$messages = Message::fromCompany($user->getCurrentCompany());
            return $query->fromCompany($user->getCurrentCompany());
        }

        return $query;
    }

    /**
     * Apply options to the query.
     *  customFilters: [
     *      "SHOW_OWN_PARENT_MESSAGES_ONLY"
     *  ]
     * @throws InvalidArgumentException
     */
    protected function applyCustomFilters(Builder $query, array $args, UserInterface $user): Builder
    {
        foreach ($args['customFilters'] as $option) {
            $query = match ($option) {
                'SHOW_OWN_PARENT_MESSAGES_ONLY' => $query->where(function ($q) use ($user) {
                    $q->whereNull('parent_id')
                        ->orWhereRaw('NOT EXISTS (
                        SELECT 1 FROM messages AS parent 
                        WHERE parent.id = messages.parent_id 
                        AND parent.users_id = messages.users_id
                    )');
                }),
                // Add future options here
                default => $query
            };
        }

        return $query;
    }

    public function getForYouFeed(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): LengthAwarePaginator {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        unset($args['orderBy']);
        $currentPage = (int) ($args['page'] ?? 1);
        //generate home-view interaction
        if ($app->get('TEMP_HOME_VIEW_EVENT') && $currentPage === 2) {
            UserInteractionJob::dispatch(
                $app,
                $user,
                $app,
                InteractionEnum::VIEW_HOME_PAGE->getValue()
            );
        }

        /**
         * @todo same thing don't like this, we need a better way to handle this
         */
        $scenario = ConfigurationEnum::FOR_YOU_SCENARIO;
        if ($app->get('trending-if-no-interaction')) {
            $hasDoneAnyInteraction = ! empty($user->get(UserConfigEnum::USER_INTERACTIONS->value));
            $scenario = $hasDoneAnyInteraction ? ConfigurationEnum::FOR_YOU_SCENARIO : ConfigurationEnum::TRENDING_SCENARIO;
        }

        /**
         * @todo this is tied to recombee, we need to move it to a per application
         * configuration
         */
        $recombeeUserRecommendationService = new GenerateRecommendForYourFeedAction($app, $company);

        return $recombeeUserRecommendationService->execute(
            $user,
            $currentPage,
            $args['first'] ?? 15,
            $scenario->value
        );
    }

    public function getCustomFeed(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): LengthAwarePaginator {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        unset($args['orderBy']);
        $currentPage = (int) ($args['page'] ?? 1);
        //generate home-view interaction
        if ($app->get('TEMP_HOME_VIEW_EVENT') && $currentPage === 2) {
            UserInteractionJob::dispatch(
                $app,
                $user,
                $app,
                InteractionEnum::VIEW_HOME_PAGE->getValue()
            );
        }

        $recombeeUserRecommendationService = new GenerateRecommendCustomFeedAction($app, $company);

        return $recombeeUserRecommendationService->execute(
            $user,
            $currentPage,
            $args['first'] ?? 15,
            $args['scenario'] ?? ConfigurationEnum::FOR_YOU_SCENARIO->value
        );
    }

    public function getFollowingFeed(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        $user = auth()->user();
        $app = app(Apps::class);

        $messageTypeId = $app->get('social-user-message-filter-message-type');

        return UserMessage::getUserMessageFollowingFeed($user, $app)->when(
            $messageTypeId !== null,
            function ($query) use ($messageTypeId) {
                return $query->where('messages.message_types_id', $messageTypeId);
            }
        );
    }

    public function getChannelMessages(
        mixed $root,
        array $args,
        GraphQLContext $context,
        ResolveInfo $resolveInfo
    ): Builder {
        if (isset($args['channel_uuid']) && isset($args['channel_slug'])) {
            throw new InvalidArgumentException('Provide only one of channel_uuid or channel_slug, not both.');
        }

        return Message::fromApp()
            ->whereHas('channels', function ($query) use ($args) {
                if (isset($args['channel_uuid'])) {
                    $query->where('channels.uuid', $args['channel_uuid']);
                } elseif (isset($args['channel_slug'])) {
                    $query->where('channels.slug', $args['channel_slug']);
                }
            })
            ->when(! auth()->user()->isAdmin(), function ($query) {
                $query->where('companies_id', auth()->user()->currentCompanyId());
            });
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

    public function viewMessageHistory(mixed $root, array $request): Builder
    {
        $messagePath = Message::where('id', $request['message_id'])->value('path')->getValue();

        if (! $messagePath) {
            throw new Exception('Message does not a have history');
        }

        $messageHistory = Message::query()->whereIn('id', explode('.', $messagePath))
            ->where('is_deleted', 0)
            ->where('is_locked', 0);

        return $messageHistory;
    }
}
