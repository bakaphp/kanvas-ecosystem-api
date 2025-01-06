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
use Kanvas\Social\Follows\Repositories\UsersFollowsRepository;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Notifications\NewMessageNotification;

use function Sentry\captureException;

use Throwable;

class SendMessageNotificationsToAllFollowersJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Message $message,
        protected array $config,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->message->app);
        $newMessageNotification = new NewMessageNotification(
            $this->message,
            $this->config,
            $this->config['via']
        );
        //$newMessageNotification->setFromUser($this->message->user);
        $chunkSize = 250; // per page

        UsersFollowsRepository::getFollowersBuilder($this->message->user, $this->message->app)->chunk(
            $chunkSize,
            function ($followers) use ($newMessageNotification) {
                foreach ($followers as $follower) {
                    try {
                        $follower->notify($newMessageNotification);
                    } catch (Throwable $e) {
                        captureException($e);

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
