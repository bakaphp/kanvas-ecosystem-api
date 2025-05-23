<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PromptMine\Enums\NotificationTemplateEnum;
use Kanvas\Connectors\PromptMine\Notifications\MonthlyMessageCreationNotification;
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
        protected readonly int $monthtlyCount,
        protected MessageType $messageType,
        protected array $via,
    ) {
    }

    /**
     * Send message notifications to all followers of a user.
     */
    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $monthlyCountNotification = new MonthlyMessageCreationNotification(
            $this->user,
            [
                'push_template' => NotificationTemplateEnum::PUSH_MONTHLY_PROMPT_COUNT->value,
                'title' => "You created $this->monthtlyCount prompts this month!",
                'message' => "Amazing work! Keep the streak going. Unlock even more creative ideas.",
            ],
            $this->via
        );
        $this->user->notify($monthlyCountNotification);
    }
}
