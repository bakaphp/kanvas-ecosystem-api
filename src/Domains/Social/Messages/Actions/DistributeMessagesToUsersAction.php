<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\Models\Message;

class DistributeMessagesToUsersAction
{
    public function __construct(
        private Message $message,
        private Apps $app,
        private array $params = []
    ) {
    }

    public function execute(): int
    {
        $totalDelivered = 0;
        $user = $this->message->user;

        // Use the repository to get followers
        $followersBuilder = UsersFollowsRepository::getFollowersBuilder($user, $this->app);

        // Process in chunks to avoid memory issues
        $followersBuilder->chunk(100, function ($followers) use (&$totalDelivered) {
            foreach ($followers as $follower) {
                // Create user message using the dedicated action
                $createUserMessageAction = new CreateUserMessageAction(
                    $this->message,
                    $follower,
                    $this->params['activity'] ?? []
                );

                $createUserMessageAction->execute();
                $totalDelivered++;
            }
        });

        return $totalDelivered;
    }
}
