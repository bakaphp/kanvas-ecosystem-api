<?php

declare(strict_types=1);

namespace Kanvas\Social\Interactions\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Traits\KanvasJobsTrait;
use Baka\Users\Contracts\UserInterface;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Kanvas\Connectors\Google\Actions\GenerateGoogleUserMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;

class GenerateUserMessageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public $failOnTimeout = false;
    public $uniqueFor = 30;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user
    ) {
        $this->onQueue('user-interactions');
    }

    /**
     * Unique ID for the job to prevent duplicate processing.
     */
    public function uniqueId()
    {
        return 'generate_user_message_' . $this->user->getId() . '_' . $this->app->getId();
    }

    public function middleware(): array
    {
        if (! $this->uniqueFor) {
            return [];
        }

        return [
            (new WithoutOverlapping($this->uniqueId()))->expireAfter($this->uniqueFor),
        ];
    }

    public function handle()
    {
        config(['laravel-model-caching.disabled' => true]);
        $this->overwriteAppService($this->app);

        $recommendationEngine = $this->app->get('social-user-feed-recommendation-engine') ?? 'local';
        $pageSize = $this->app->get('user-message-page-size') ?? 350;
        $cleanUserFeed = $this->app->get('social-clean-user-feed') ?? true;
        $isDevelopment = ! App::environment('production');

        if ($recommendationEngine == 'google') {
            $generateUserMessage = new GenerateGoogleUserMessageAction(
                $this->app,
                $this->company,
                $this->user,
                $cleanUserFeed
            );
            $generateUserMessage->execute($pageSize);
        } elseif ($isDevelopment && $recommendationEngine == 'local') {
            $this->executeRandomMessages($pageSize);
        }
    }

    public function executeRandomMessages(int $pageSize = 350): int
    {
        $cleanUserFeed = $this->app->get('social-clean-user-feed') ?? true;

        // Get random message IDs for the app
        $randomMessages = Message::fromApp($this->app)
            ->inRandomOrder()
            ->limit($pageSize);

        $messageTypeId = $this->app->get('social-user-message-filter-message-type');

        if ($messageTypeId !== null) {
            $randomMessages->where('message_types_id', $messageTypeId);
        }

        $randomMessages = $randomMessages->get();

        DB::transaction(function () use ($randomMessages, $cleanUserFeed) {
            if ($cleanUserFeed) {
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
            $secondsInterval = $totalSeconds / max(count($randomMessages), 1);
            foreach ($randomMessages as $index => $message) {
                // Lock the record if it exists, or prepare to create a new one
                $existingUserMessage = UserMessage::withTrashed()->where([
                        'messages_id' => $message->id,
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
                        'messages_id' => $message->id,
                        'users_id' => $this->user->getId(),
                        'apps_id' => $this->app->getId(),
                        'is_deleted' => 0,
                        'created_at' => Carbon::now()->subSeconds($totalSeconds - ($index * $secondsInterval)),
                    ]);
                }
            }
        });

        return count($randomMessages);
    }
}
