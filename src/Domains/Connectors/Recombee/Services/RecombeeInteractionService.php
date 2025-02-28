<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Services;

use Baka\Contracts\AppInterface;
use InvalidArgumentException;
use Kanvas\Connectors\Recombee\Client;
use Kanvas\Social\Enums\InteractionEnum;
use Kanvas\Social\Interactions\Models\UsersInteractions;
use Recombee\RecommApi\Client as RecommApiClient;
use Recombee\RecommApi\Requests\AddBookmark;
use Recombee\RecommApi\Requests\AddDetailView;
use Recombee\RecommApi\Requests\AddPurchase;
use Recombee\RecommApi\Requests\AddRating;

class RecombeeInteractionService
{
    protected RecommApiClient $client;

    public function __construct(
        protected AppInterface $app,
        ?string $recombeeDatabase = null,
        ?string $recombeeApiKey = null,
        ?string $recombeeRegion = 'ca-east'
    ) {
        $this->client = (new Client(
            $app,
            $recombeeDatabase,
            $recombeeApiKey,
            $recombeeRegion
        ))->getClient();
    }

    public function addUserInteraction(UsersInteractions $userInteraction): mixed
    {
        $interactionType = $userInteraction->interaction->name ?? null;

        if ($interactionType === null) {
            throw new InvalidArgumentException('Missing interaction type.');
        }

        $interactionMap = [
            InteractionEnum::VIEW->getValue() => AddDetailView::class,
            InteractionEnum::VIEW_ITEM->getValue() => AddDetailView::class,
            InteractionEnum::VIEW_HOME_PAGE->getValue() => AddDetailView::class,
            InteractionEnum::VIEW_ITEM_LIST->getValue() => AddDetailView::class,
            InteractionEnum::SHARE->getValue() => AddRating::class,
            InteractionEnum::LIKE->getValue() => AddRating::class,
            InteractionEnum::FOLLOW->getValue() => AddRating::class,
            InteractionEnum::DISLIKE->getValue() => AddRating::class,
            InteractionEnum::SAVE->getValue() => AddBookmark::class,
            InteractionEnum::PURCHASE->getValue() => AddPurchase::class,
        ];

        if (! isset($interactionMap[$interactionType])) {
            throw new InvalidArgumentException('Invalid interaction type: ' . $interactionType);
        }

        $interactionClass = $interactionMap[$interactionType];

        $parameters = [
            'timestamp' => $userInteraction->created_at->timestamp,
            'cascadeCreate' => true,
        ];

        $likeStyleInteraction = [
            InteractionEnum::LIKE->getValue(),
            InteractionEnum::SHARE->getValue(),
            InteractionEnum::FOLLOW->getValue(),
        ];
        // Handle rating values
        if ($interactionClass === AddRating::class) {
            $value = in_array($interactionType, $likeStyleInteraction) ? 1 : -1;
            $request = new $interactionClass(
                (string) $userInteraction->users_id,
                $userInteraction->entity_id,
                $value,
                $parameters
            );
        } else {
            $request = new $interactionClass(
                (string) $userInteraction->users_id,
                $userInteraction->entity_id,
                $parameters
            );
        }

        return $this->client->send($request);
    }
}
