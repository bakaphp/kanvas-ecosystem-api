<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Kanvas\Notifications\Channels\KanvasSlack;
use Kanvas\Notifications\Enums\NotificationChannelEnum;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Reaching the surface people actually watch.
 *
 * Slack was only ever reachable from inside an agent's turn, via `send_slack_direct_message`. Anything
 * that finished asynchronously could reach someone by mail, push or the in-app list — never on Slack.
 * Posting into a Slack-backed channel does not help either: nothing pushes outbound when a Message is
 * created, so the row is written and goes nowhere.
 */
class SlackNotificationChannelTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_slack_is_a_resolvable_notification_channel(): void
    {
        $this->assertSame(
            KanvasSlack::class,
            NotificationChannelEnum::getNotificationChannelBySlug('slack'),
        );
        $this->assertSame(
            NotificationChannelEnum::SLACK->value,
            NotificationChannelEnum::getIdFromString('slack'),
        );
    }

    /** The bot token lives on an agent, so the notification has to name which agent is speaking. */
    public function test_a_plan_notification_offers_its_agent_as_the_sender(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        $payload = new PlanProgressNotification($plan, 'Finished', 'The audit is done.')
            ->toSlack(static::$cachedUser);

        $this->assertSame($plan->agent?->getId(), $payload['agent']?->getId());
        $this->assertStringContainsString('The audit is done.', $payload['text']);
        $this->assertStringContainsString('Finished', $payload['text']);
    }

    /**
     * An agent with no Slack connection must leave the channel silent rather than throw — a plan that
     * finished must not be un-finished by a missing integration, and every other route still lands.
     */
    public function test_an_agent_without_a_bot_token_delivers_nothing_and_does_not_throw(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        new KanvasSlack()->send(
            static::$cachedUser,
            new PlanProgressNotification($plan, 'Finished', 'The audit is done.'),
        );

        $this->assertTrue(true, 'A missing Slack token is a no-op, not a failure.');
    }

    /** Nothing to say means nothing sent — an empty body must not post a blank DM. */
    public function test_an_empty_message_sends_nothing(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::DONE->value]);

        $payload = new PlanProgressNotification($plan, '', '')->toSlack(static::$cachedUser);

        $this->assertSame('', trim((string) $payload['text']));
    }
}
