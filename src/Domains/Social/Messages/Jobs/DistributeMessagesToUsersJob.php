<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Messages\Models\UserMessage;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;

class DistributeMessagesToUsersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Message $message,
        protected Apps $app,
        protected array $params = [],
    ) {}

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        UsersFollows::fromApp($this->app)
            ->where('entity_id', $this->message->users_id)
            ->where('entity_namespace', Users::class)
            ->where('is_deleted', 0)
            ->chunk(100, function ($userFollows) {
                foreach ($userFollows as $userFollow) {
                    $userMessage = UserMessage::updateOrCreate(
                        [
                            'apps_id' => $this->app->getId(),
                            'message_id' => $this->message->getId(),
                            'users_id' => $userFollow->users_id,
                            'is_deleted' => 0
                        ]
                    );

                    Log::info('Distributed message: ' . $this->message->getId() . ' to user: ' . $userMessage->users_id);
                }
            });
    }
}
