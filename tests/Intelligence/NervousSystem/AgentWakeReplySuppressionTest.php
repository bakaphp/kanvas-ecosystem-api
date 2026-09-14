<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\Traits\DrivesAgentWake;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * One wake is one message.
 *
 * A wake job posts the agent's turn when it returns, but the agent can also write on the same board
 * mid-turn with comment_on_nervous_system_plan — so the reply lands seconds after the comment, saying
 * the same thing, the second one narrating the first ("Actions Taken: commented on the thread..."). It
 * happened to the PM of project 1834 the moment it was given the comment tool (messages #194250 and
 * #194251, four seconds apart on plan 22974's board).
 *
 * The guard has to read the message's own payload AND its author, because neither alone is enough: a
 * PM shares its user with the person it talks to, and a channel carries other agents' posts too.
 */
final class AgentWakeReplySuppressionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testAnAgentCommentPostedDuringTheRunSuppressesTheFinalReply(): void
    {
        [$plan, $channel, $author] = $this->board();
        $baseline = $this->guard()->latestOn($channel);

        $this->agentComment($plan, 'Blocked: message_type_id is missing. @kaioken can you provide it?', $author);

        $this->assertTrue($this->guard()->postedDuringRun($channel, $baseline, $author));
    }

    /**
     * The reason this is not a message COUNT. Agents share users with real people, so the person the
     * agent is answering may write on the same board, as the same user, while the turn is running —
     * and a count would read that as the agent having already spoken and swallow the answer.
     */
    public function testAPersonWritingOnTheSharedUserDuringTheRunDoesNotSuppressTheReply(): void
    {
        [, $channel, $author] = $this->board();
        $baseline = $this->guard()->latestOn($channel);

        new PostChannelMessageAction(
            channel: $channel,
            author: $author,
            verb: 'agent_reply',
            content: 'Use message_type_id 4.',
        )->execute();

        $this->assertFalse($this->guard()->postedDuringRun($channel, $baseline, $author));
    }

    /** Another agent talking on the board is not this agent having said its piece. */
    public function testAnotherAgentPostingDuringTheRunDoesNotSuppressTheReply(): void
    {
        [$plan, $channel, $author] = $this->board();
        $baseline = $this->guard()->latestOn($channel);

        $this->agentComment($plan, 'Recording that the import finished.', $this->someoneElse());

        $this->assertFalse($this->guard()->postedDuringRun($channel, $baseline, $author));
    }

    /** Notes already on the record are what the agent is being woken about, not what it just said. */
    public function testACommentLeftBeforeTheRunDoesNotSuppressTheReply(): void
    {
        [$plan, $channel, $author] = $this->board();

        $this->agentComment($plan, 'Starting on this now.', $author);
        $baseline = $this->guard()->latestOn($channel);

        $this->assertFalse($this->guard()->postedDuringRun($channel, $baseline, $author));
    }

    /** A wake with no channel to answer on posts nothing to duplicate. */
    public function testNoChannelSuppressesNothing(): void
    {
        [, , $author] = $this->board();

        $this->assertFalse($this->guard()->postedDuringRun(null, null, $author));
    }

    /**
     * @return array{0: Plan, 1: Channel, 2: Users}
     */
    private function board(): array
    {
        $agent = $this->makeAgent();
        $plan = $this->makePlan([], $agent);

        /** @var Channel|null $channel */
        $channel = $plan->socialChannels()->first();
        $this->assertNotNull($channel, 'A plan with no Activities channel cannot be posted on at all.');

        $author = $agent->user;
        $this->assertNotNull($author, 'The guard compares against the agent\'s own user.');

        return [$plan, $channel, $author];
    }

    private function agentComment(Plan $plan, string $content, Users $author): void
    {
        // The action swallows its own failures, so an un-posted comment would read as "not a duplicate".
        $this->assertNotNull(
            new PostPlanActivityMessageAction($plan, $content, author: $author)->execute(),
            'The comment was never posted, so this asserts nothing about suppression.'
        );
    }

    /**
     * A distinct identity that can actually post — `MessageObserver` counts every message against the
     * author's app profile, so a user without one cannot write at all.
     */
    private function someoneElse(): Users
    {
        $user = Users::factory()->create();

        UsersAssociatedApps::updateOrCreate(
            [
                'users_id' => $user->getId(),
                'apps_id' => $this->makeAgent()->apps_id,
                'companies_id' => 0,
            ],
            [
                'identify_id' => (string) $user->getId(),
                'displayname' => 'other' . fake()->unique()->lexify('?????'),
                'password' => $user->password,
                'email' => $user->email,
                'user_active' => 1,
                'status' => 1,
            ],
        );

        return $user;
    }

    /**
     * The trait under test, with its two protected helpers reachable. Both wake jobs share it, so the
     * behaviour belongs to the trait rather than to either job.
     */
    private function guard(): object
    {
        return new class () {
            use DrivesAgentWake;

            public function latestOn(?Channel $channel): ?int
            {
                return $this->latestMessageId($channel);
            }

            public function postedDuringRun(?Channel $channel, ?int $since, ?Users $author): bool
            {
                return $this->agentPostedDuringRun($channel, $since, $author);
            }
        };
    }
}
