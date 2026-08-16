<?php

declare(strict_types=1);

namespace Kanvas\Auth\Notifications;

use Baka\Contracts\AppInterface;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Slack\BlockKit\Blocks\SectionBlock;
use Illuminate\Notifications\Slack\SlackMessage;

/**
 * Extends Illuminate's notification rather than Kanvas\Notifications\Notification
 * because this is an ops alert routed to a webhook, and the Kanvas base maps
 * channel slugs for user-facing delivery only — it has no slack channel.
 * Mirrors Connectors\Elead\Notifications\ApiUsageLimitNotification.
 */
class SignupAnomalyNotification extends Notification
{
    public function __construct(
        protected AppInterface $app,
        protected int $signupsLastHour,
        protected float $hourlyBaseline,
        protected int $blockedLastHour,
        protected int $multiplier,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['slack'];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $baseline = number_format($this->hourlyBaseline, 1);
        $factor = $this->hourlyBaseline > 0.0
            ? number_format((float) $this->signupsLastHour / $this->hourlyBaseline, 1) . '×'
            : 'no baseline';

        return new SlackMessage()
            ->headerBlock('Signup spike: ' . $this->app->name)
            ->sectionBlock(function (SectionBlock $block) use ($baseline, $factor): void {
                $block->text(
                    "App: *{$this->app->name}* (ID: {$this->app->getId()})\n"
                    . "Signups in the last hour: *{$this->signupsLastHour}* ({$factor} the 7-day hourly average of {$baseline})\n"
                    . "Blocked as spam in the same hour: *{$this->blockedLastHour}*\n"
                    . "Alert threshold: {$this->multiplier}× baseline"
                );
            })
            ->sectionBlock(function (SectionBlock $block): void {
                $block->text(
                    'A high blocked count means the filters are holding. '
                    . 'A high signup count with a low blocked count means the campaign is getting through.'
                );
            });
    }
}
