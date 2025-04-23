<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Recombee\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Recombee\Services\RecombeeUserRecommendationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class GenerateRecombeeUserMessageAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected bool $cleanUserFeed = false
    ) {
    }

    public function execute(int $pageSize = 350): int
    {
        $recommendationService = new RecombeeUserRecommendationService($this->app);
        $userForYouFeed = $recommendationService->getUserForYouFeed($this->user, $pageSize, 'for-you-feed')['recomms'];
        if (count($userForYouFeed) === 0) {
            $userForYouFeed = $recommendationService->getUserForYouFeed($this->user, $pageSize, 'trending')['recomms'];
        }

        $messageTypeId = $this->app->get('social-user-message-filter-message-type');
        $processedIds = [];

        DB::transaction(function () use ($userForYouFeed, $messageTypeId, &$processedIds) {
            $totalSeconds = 200;
            $secondsInterval = $totalSeconds / count($userForYouFeed);

            // First process all new messages
            foreach ($userForYouFeed as $index => $messageData) {
                $messageId = $messageData['id'];

                // Check if the message still exists
                if (! Message::fromApp($this->app)
                        ->where('id', $messageId)
                        ->when($messageTypeId !== null, function ($query) use ($messageTypeId) {
                            return $query->where('message_types_id', $messageTypeId);
                        })
                        ->where('users_id', '!=', $this->user->getId())
                        ->exists()
                ) {
                    continue;
                }

                $processedIds[] = $messageId;

                $existingUserMessage = UserMessage::withTrashed()->where([
                    'messages_id' => $messageId,
                    'users_id'    => $this->user->getId(),
                    'apps_id'     => $this->app->getId(),
                ])
                ->lockForUpdate()
                ->first();

                if ($existingUserMessage) {
                    $existingUserMessage->update([
                        'is_deleted' => 0,
                        'created_at' => Carbon::now()->subSeconds($totalSeconds - ($index * $secondsInterval)),
                    ]);
                } else {
                    UserMessage::create([
                        'messages_id' => $messageId,
                        'users_id'    => $this->user->getId(),
                        'apps_id'     => $this->app->getId(),
                        'is_deleted'  => 0,
                        'created_at'  => Carbon::now()->subSeconds($totalSeconds - ($index * $secondsInterval)),
                    ]);
                }
            }

            // Then clean up old messages, excluding the ones we just processed
            if ($this->cleanUserFeed && ! empty($processedIds)) {
                UserMessage::fromApp($this->app)
                    ->where('users_id', $this->user->getId())
                    ->whereNotIn('messages_id', $processedIds) // Don't delete messages we just processed
                    ->where(function ($query) {
                        $query->where('is_liked', 0)
                            ->where('is_disliked', 0)
                            ->where('is_saved', 0)
                            ->where('is_purchased', 0)
                            ->where('is_shared', 0);
                    })
                    ->lockForUpdate()
                    ->delete();

                // Update the created_at timestamp for the messages we didnt process
                UserMessage::fromApp($this->app)
                    ->where('users_id', $this->user->getId())
                    ->whereNotIn('messages_id', $processedIds)
                    ->where(function ($query) {
                        $query->where('is_liked', 1)
                            ->orWhere('is_disliked', 1)
                            ->orWhere('is_saved', 1)
                            ->orWhere('is_purchased', 1)
                            ->orWhere('is_shared', 1);
                    })
                    ->lockForUpdate()
                    ->update(['created_at' => Carbon::now()]);
            }
        });

        return count($userForYouFeed);
    }
}
