<?php

declare(strict_types=1);

namespace Kanvas\Notifications\Jobs;

use Baka\Contracts\AppInterface;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Notifications\Models\NotificationTypes;
use Kanvas\Notifications\Templates\DynamicKanvasNotification;
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\DataTransferObject\MessagesNotificationMetadata;
use Kanvas\Users\Models\Users;
use Throwable;

class SendMessageNotificationsToAllFollowersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Users $fromUser,
        protected AppInterface $app,
        protected NotificationTypes $notificationType,
        protected MessagesNotificationMetadata $messagePayload,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        $dynamicNotification = new DynamicKanvasNotification(
            $this->notificationType,
            $this->messagePayload->message
        );
        $dynamicNotification->setFromUser($this->fromUser);

        $chunkSize = 1000; // per page

        UsersFollowsRepository::getFollowersBuilder($this->fromUser, $this->app)->chunk(
            $chunkSize,
            function ($followers) use ($dynamicNotification) {
                foreach ($followers as $follower) {
                    try {
                        $follower->notify($dynamicNotification);
                    } catch (Throwable $e) {
                        Log::error('Error in notification to user : ' . $follower->displayname . ' ' . $e->getMessage(), [
                            'job' => self::class,
                            'exception' => $e,
                        ]);

                        continue;
                    }
                }
            }
        );
    }
}
