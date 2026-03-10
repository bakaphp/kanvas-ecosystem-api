<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Notifications;

use Baka\Contracts\AppInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;

class ApiUsageLimitNotification extends Notification
{
    protected string $slackChannel;

    public function __construct(
        protected Companies $company,
        protected AppInterface $app,
        protected int $currentCount,
        protected int $dailyLimit,
        protected int $thresholdPercentage,
    ) {
        $this->slackChannel = (string) ($this->app->get(ConfigurationEnum::ELEAD_SLACK_CHANNEL->value) ?? 'integrations');
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $remaining = $this->dailyLimit - $this->currentCount;
        $usagePercent = $this->dailyLimit > 0
            ? (int) round((float) $this->currentCount / (float) $this->dailyLimit * 100.0)
            : 0;

        return new SlackMessage()
            ->to($this->slackChannel)
            ->headerBlock("Elead API Usage Alert: {$this->thresholdPercentage}%")
            ->sectionBlock(function (SectionBlock $block) use ($remaining, $usagePercent) {
                $block->text(
                    "Company: *{$this->company->name}* (ID: {$this->company->getId()})\n"
                    . "Current calls: *{$this->currentCount}* / {$this->dailyLimit} ({$usagePercent}%)\n"
                    . "Remaining: *{$remaining}*"
                );
            });
    }
}
