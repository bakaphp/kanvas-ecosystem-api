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
        $userForYouFeed = $recommendationService->getUserForYouFeed($this->user, $pageSize, 'for-you-feed');

        DB::transaction(function () use ($userForYouFeed) {
            if ($this->cleanUserFeed) {
                // Lock the user messages for this app and user to avoid concurrent jobs
                UserMessage::fromApp($this->app)
                    ->where('users_id', $this->user->getId())
                    ->where(function ($query) {
                        $query->where('is_liked', 0)
                            ->where('is_disliked', 0)
                            ->where('is_saved', 0)
                            ->where('is_purchased', 0)
                            ->where('is_shared', 0);
                    })
                    ->lockForUpdate()
                    ->delete();
            }

            $totalSeconds = 200;
            $secondsInterval = $totalSeconds / count($userForYouFeed);
            $messageTypeId = $this->app->get('social-user-message-filter-message-type');

            foreach ($userForYouFeed as $index => $messageId) {
                $messageId = $messageId['id'];

                // Check if the message still exists
                if (! Message::fromApp($this->app)
                        ->where('id', $messageId->getId())
                        ->when($messageTypeId !== null, function ($query) use ($messageTypeId) {
                            return $query->where('message_types_id', $messageTypeId);
                        })
                        ->where('users_id', '!=', $this->user->getId())
                        ->exists()
                ) {
                    continue;
                }

                $existingUserMessage = UserMessage::withTrashed()->where([
                    'messages_id' => $messageId->getId(),
                    'users_id' => $this->user->getId(),
                    'apps_id' => $this->app->getId(),
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
                        'messages_id' => $messageId->getId(),
                        'users_id' => $this->user->getId(),
                        'apps_id' => $this->app->getId(),
                        'is_deleted' => 0,
                        'created_at' => Carbon::now()->subSeconds($totalSeconds - ($index * $secondsInterval)),
                    ]);
                }
            }
        });

        return count($userForYouFeed);
    }
}
