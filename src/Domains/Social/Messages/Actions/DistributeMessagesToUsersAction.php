<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Models\UsersFollows;
use Kanvas\Social\Messages\Models\UserMessage;
use Illuminate\Support\Facades\Log;

class DistributeMessagesToUsersAction
{
    public function __construct(
        private Message $message,
        private Apps $app,
        private array $params = []
    ) {
    }

    public function execute(): bool
    {
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

        return true;
    }
}
