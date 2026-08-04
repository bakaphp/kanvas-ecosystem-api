<?php

declare(strict_types=1);

namespace Kanvas\ActionEngine\Engagements\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Notifications\Slack\SlackRoute;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Kanvas\ActionEngine\Engagements\Actions\MessageNotificationTextAction;
use Kanvas\ActionEngine\Engagements\Actions\ResolveEngagementStagePositionAction;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Engagements\Notifications\EngagementPipelineStageNotification;
use Throwable;

class NotifyEngagementPipelineStageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Engagement $engagement,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->engagement->app);

        $position = new ResolveEngagementStagePositionAction($this->engagement)->execute();
        if ($position === null) {
            return;
        }

        if ($this->engagement->message === null) {
            return;
        }

        $messageText = new MessageNotificationTextAction($this->engagement)->notificationText();
        if ($messageText === '') {
            return;
        }

        $slackChannel = (string) ($this->engagement->app->get('slack_channel') ?? '');
        $slackBotToken = (string) ($this->engagement->app->get('slack_bot_token') ?? '');

        if ($slackChannel === '' || $slackBotToken === '') {
            return;
        }

        try {
            Notification::route(
                'slack',
                SlackRoute::make(
                    $slackChannel,
                    $slackBotToken
                )
            )->notify(
                new EngagementPipelineStageNotification(
                    $slackChannel,
                    $messageText
                )
            );
        } catch (Throwable $e) {
            Log::warning('Failed to post engagement pipeline-stage Slack note', [
                'engagement_id' => $this->engagement->getId(),
                'slack_channel' => $slackChannel,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
