<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Notifications\MonthlyMessageCreationNotification;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Users\Models\Users;

class SendMonthlyMessageCountJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use KanvasJobsTrait;

    public function __construct(
        protected Apps $app,
        protected Users $user,
        protected int $monthtlyCount,
        protected MessageType $messageType,
        protected array $config,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        // $newMessageNotification = new NewMessageNotification(
        //     $this->config,
        //     $this->config['via']
        // );

        $monthlyCountNotification = new MonthlyMessageCreationNotification(
            $this->user,
            [
                'push_template' => 'push_new_message',
                'message_count' => $this->monthtlyCount,
            ],
            $this->config['via']
        );
    }
}
