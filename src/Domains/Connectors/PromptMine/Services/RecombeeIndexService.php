<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Services;

use Baka\Contracts\AppInterface;
use InvalidArgumentException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Users\Models\Users;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\AddBookmark;
use Recombee\RecommApi\Requests\AddDetailView;
use Recombee\RecommApi\Requests\AddItemProperty;
use Recombee\RecommApi\Requests\AddUserProperty;
use Recombee\RecommApi\Requests\SetUserValues;
use Recombee\RecommApi\Requests\AddPurchase;
use Recombee\RecommApi\Requests\AddRating;
use Recombee\RecommApi\Requests\ListItemProperties;
use Recombee\RecommApi\Requests\ListUserProperties;
use Recombee\RecommApi\Requests\SetItemValues;
use Kanvas\Users\Repositories\UsersInteractionsRepository;

class RecombeeIndexService
{
    protected RecommApiClient $client;

    public function __construct(
        protected AppInterface $app,
        ?string $recombeeDatabase = null,
        ?string $recombeeApiKey = null,
        string $recombeeRegion = 'ca-east'
    ) {
        $this->client = (new Client($app, $recombeeDatabase, $recombeeApiKey, $recombeeRegion))->getClient();
    }

    public function createPromptMessageDatabase(): void
    {
        $properties = [
            'title' => 'string',
            'description' => 'string',
            'message_type' => 'string',
            'total_like' => 'int',
            'total_dislike' => 'int',
            'total_share' => 'int',
            'total_save' => 'int',
            'total_purchase' => 'int',
            'created_at' => 'timestamp',
            'updated_at' => 'timestamp',
            'is_premium' => 'boolean',
            'ai_model' => 'string',
            'image' => 'string',
            'categories' => 'set',
        ];
        $existingProperties = $this->client->send(new ListItemProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                // Property does not exist, add it
                $this->client->send(new AddItemProperty($property, $type));
            }
        }
    }


    public function createUsersDatabase(): void
    {
        $properties = [
            'firstname' => 'string',
            'lastname' => 'string',
            'email' => 'string',
            'displayname' => 'string',
            'liked_categories' => 'set',
        ];
        $existingProperties = $this->client->send(new ListUserProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                // Property does not exist, add it
                $this->client->send(new AddUserProperty($property, $type));
            }
        }
    }

    public function createUsersFollowsItemsDatabase(): void
    {
        $properties = [
            'users_id' => 'int',
            'entity_id' => 'int',
            // 'entity_messages_posts_categories' => 'set',
            'entity_liked_categories' => 'set'
        ];
        $existingProperties = $this->client->send(new ListItemProperties());
        $existingPropertyNames = array_column($existingProperties, 'name');

        foreach ($properties as $property => $type) {
            if (! in_array($property, $existingPropertyNames)) {
                // Property does not exist, add it
                $this->client->send(new AddItemProperty($property, $type));
            }
        }
    }

    public function indexPromptMessage(Message $message): mixed
    {
        $messageData = $message->message;

        if (empty($messageData['title']) || empty($messageData['prompt'])) {
            throw new InvalidArgumentException('Message data is missing required fields.');
        }

        $request = new SetItemValues(
            $message->getId(),
            [
                'title' => $messageData['title'],
                'description' => $messageData['prompt'],
                'message_type' => $message->messageType->name,
                'total_like' => $message->total_liked,
                'total_dislike' => $message->total_disliked,
                'total_share' => $message->total_shared,
                'total_save' => $message->total_saved,
                'total_purchase' => $message->total_purchased,
                'created_at' => (int) strtotime($message->created_at->toDateTimeString()),
                'updated_at' => (int) strtotime($message->updated_at->toDateTimeString()),
                'is_premium' => $message->is_premium,
                'ai_model' => $messageData['ai_model']['name'] ?? null,
                'image' => $messageData['ai_image']['image'] ?? null,
                'categories' => $message->tags->pluck('name')->toArray(),
            ],
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    public function indexUserInteraction(UsersInteractions $userInteraction): mixed
    {
        $interactionType = $userInteraction->interaction->name ?? null;

        if (! $interactionType) {
            throw new InvalidArgumentException('Missing interaction type.');
        }

        $interactionMap = [
            InteractionEnum::VIEW->getValue() => AddDetailView::class,
            InteractionEnum::LIKE->getValue() => AddRating::class,
            InteractionEnum::DISLIKE->getValue() => AddRating::class,
            InteractionEnum::SAVE->getValue() => AddBookmark::class,
            InteractionEnum::PURCHASE->getValue() => AddPurchase::class,
        ];

        if (! isset($interactionMap[$interactionType])) {
            throw new InvalidArgumentException('Invalid interaction type: ' . $interactionType);
        }

        $interactionClass = $interactionMap[$interactionType];

        $parameters = [
            'timestamp' => $userInteraction->created_at?->timestamp ?? time(),
            'cascadeCreate' => true,
        ];

        // Handle rating values
        if ($interactionClass === AddRating::class) {
            $value = ($interactionType === InteractionEnum::LIKE->getValue()) ? 1 : -1;
            $request = new $interactionClass($userInteraction->users_id, $userInteraction->entity_id, $value, $parameters);
        } else {
            $request = new $interactionClass($userInteraction->users_id, $userInteraction->entity_id, $parameters);
        }

        return $this->client->send($request);
    }

    public function indexUsers(Users $user, Companies $company): mixed
    {
        $userLikedCategories = UsersInteractionsRepository::getUserLikedTagsByInteractions(
            Message::class,
            [InteractionEnum::LIKE->getValue()],
            $user,
            $company,
            $this->app
        );

        $request = new SetUserValues(
            $user->getId(),
            [
                'firstname' => $user->firstname,
                'lastname' => $user->lastname,
                'email' => $user->email,
                'displayname' => $user->displayname,
                'liked_categories' => json_encode(array_values(array_unique($userLikedCategories))),
            ],
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    public function indexTags(Tag $tag): mixed
    {
        $request = new SetItemValues(
            'tag_' . $tag->slug,
            [
                'type' => 'tag',
                'item_value' => $tag->slug
            ],
            ['cascadeCreate' => true]
        );

        return $this->client->send($request);
    }

    public function indexUsersFollows(UsersFollows $usersFollow, Companies $company): mixed
    {
        $userLikedCategories = UsersInteractionsRepository::getUserLikedTagsByInteractions(
            Message::class,
            [InteractionEnum::LIKE->getValue()],
            $usersFollow->entity,
            $company,
            $this->app
        );

        // $userMessagesCategories = UsersInteractionsRepository::getUserLikedTagsByInteractions(
        //     Message::class,
        //     [InteractionEnum::LIKE->getValue()],
        //     $usersFollow->entity,
        //     $company,
        //     $this->app
        // );

        $request = new SetItemValues(
            $usersFollow->getId(),
            [
                'users_id' => $usersFollow->users_id,
                'entity_id' => $usersFollow->entity_id,
                // 'entity_messages_posts_categories' => $usersFollow->entity->tags->pluck('name')->toArray(),
                'entity_liked_categories' => json_encode(array_values(array_unique($userLikedCategories))),
            ],
            ['cascadeCreate' => true]
        );
        return $this->client->send($request);
    }
}
