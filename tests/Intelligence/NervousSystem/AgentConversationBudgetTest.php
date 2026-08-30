<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Jobs\RespondToMentionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AgentConversationBudget;
use Kanvas\Intelligence\Agents\Services\AgentTurnResponse;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * What stops two agents talking to each other forever.
 *
 * A person ends a conversation by not replying; agents do not. Budgeting the THREAD was not enough —
 * on plan 20355 the pair spent all six hops, posted the stop notice, then carried on under a new root
 * because a new thread meant a new counter. The budget belongs to the CHANNEL, above the thread.
 */
final class AgentConversationBudgetTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testAChannelCanBeChargedUpToItsCapAndNoFurther(): void
    {
        $channel = $this->board();

        for ($hop = 1; $hop <= AgentConversationBudget::MAX_HOPS; $hop++) {
            $this->assertTrue(AgentConversationBudget::spend($channel), "Hop {$hop} should be within budget.");
        }

        $this->assertFalse(AgentConversationBudget::spend($channel), 'The cap must hold.');
        $this->assertSame(AgentConversationBudget::MAX_HOPS, AgentConversationBudget::used($channel));
    }

    /** The escape hatch plan 20355 used: spend the budget, open a new thread, carry on. */
    public function testStartingANewThreadDoesNotBuyMoreBudget(): void
    {
        [$plan, $agent] = $this->boardWithAgent();
        $channel = $plan->socialChannels()->first();

        $this->exhaust($channel);

        Bus::fake();
        $this->comment($plan, 'Starting a brand new thread!', $agent->user);

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
        $this->assertSame(AgentConversationBudget::MAX_HOPS, AgentConversationBudget::used($channel));
    }

    /** A person re-entering is the signal the exchange is wanted — that, and only that, restores it. */
    public function testAHumanSpeakingResetsTheBudget(): void
    {
        [$plan] = $this->boardWithAgent();
        $channel = $plan->socialChannels()->first();

        $this->exhaust($channel);
        $this->humanComment($channel, 'Carry on, both of you.');

        $this->assertSame(0, AgentConversationBudget::used($channel));
    }

    /** A human is never rationed, however much the agents have spent between themselves. */
    public function testAHumanCommentWakesTheWorkerEvenOnAnExhaustedChannel(): void
    {
        [$plan] = $this->boardWithAgent();
        $this->exhaust($plan->socialChannels()->first());

        Bus::fake();
        $this->humanComment($plan->socialChannels()->first(), 'How is this going?');

        Bus::assertDispatched(WakeAgentForPlanJob::class);
    }

    /** Plan 20355 collected three stop notices; every refused hop was posting its own. */
    public function testTheStopNoticeIsClaimedOnlyOnce(): void
    {
        $channel = $this->board();

        $this->assertTrue(AgentConversationBudget::claimStopNotice($channel));
        $this->assertFalse(AgentConversationBudget::claimStopNotice($channel));
        $this->assertFalse(AgentConversationBudget::claimStopNotice($channel));
    }

    public function testAResetLetsTheStopNoticeBeAnnouncedAgain(): void
    {
        $channel = $this->board();

        AgentConversationBudget::claimStopNotice($channel);
        AgentConversationBudget::reset($channel);

        $this->assertTrue(AgentConversationBudget::claimStopNotice($channel));
    }

    /**
     * The turn that made the budget necessary: an acknowledgement answered by an acknowledgement.
     * Recognising the sentinel is what stops hops being spent on nothing.
     */
    public function testTheNoUpdateSentinelIsRecognisedHoweverAModelDressesItUp(): void
    {
        foreach (['NO_UPDATE', 'no_update', '  NO_UPDATE  ', '**NO_UPDATE**', '`NO_UPDATE`', '"NO_UPDATE."', ''] as $reply) {
            $this->assertTrue(AgentTurnResponse::isNoOp($reply), "[{$reply}] should count as nothing to add.");
        }

        foreach (['Understood. Standing by.', 'The crawl finished, PR is open.'] as $reply) {
            $this->assertFalse(AgentTurnResponse::isNoOp($reply), "[{$reply}] is a real answer.");
        }
    }

    /** A person who asks and gets silence has been ignored, so the option is never offered to them. */
    public function testTheSilenceOptionIsOfferedOnlyWhenTalkingToAnotherAgent(): void
    {
        $this->assertStringContainsString(AgentTurnResponse::NO_UPDATE, AgentTurnResponse::noOpGuidance());
        $this->assertStringContainsString('loop', AgentTurnResponse::noOpGuidance());
    }

    /**
     * "A human should take a look" has to reach one. The channel OWNER is no use — on an agent-created
     * board that is the agent itself — so the notice calls the last person who actually spoke.
     */
    public function testTheStopNoticeCallsTheLastHumanWhoSpokeNotTheChannelOwner(): void
    {
        [$plan, $agent] = $this->boardWithAgent();
        $channel = $plan->socialChannels()->first();
        $handle = $this->giveHandle(static::$cachedUser);

        $this->humanComment($channel, 'Any update?');
        $this->comment($plan, 'Working on it.', $agent->user);

        $called = new ReflectionMethod(RespondToMentionJob::class, 'callForAHuman')
            ->invoke(new RespondToMentionJob($agent, $channel->messages()->first()), $channel);

        $this->assertSame('@' . $handle, $called);
    }

    /** No person has spoken, so there is nobody to call — better silent than a mention that sends nothing. */
    public function testTheStopNoticeCallsNobodyWhenNoHumanHasSpoken(): void
    {
        [$plan, $agent] = $this->boardWithAgent();
        $channel = $plan->socialChannels()->first();

        $this->comment($plan, 'Working on it.', $agent->user);

        $called = new ReflectionMethod(RespondToMentionJob::class, 'callForAHuman')
            ->invoke(new RespondToMentionJob($agent, $channel->messages()->first()), $channel);

        $this->assertSame('', $called);
    }

    private function giveHandle(Users $user): string
    {
        $handle = 'handle' . fake()->unique()->lexify('?????');

        UsersAssociatedApps::updateOrCreate(
            [
                'users_id' => $user->getId(),
                'apps_id' => app(Apps::class)->getId(),
                'companies_id' => 0,
            ],
            [
                'identify_id' => (string) $user->getId(),
                'displayname' => $handle,
                'password' => $user->password,
                'email' => $user->email,
                'user_active' => 1,
                'status' => 1,
            ],
        );

        return $handle;
    }

    private function exhaust(Channel $channel): void
    {
        for ($hop = 1; $hop <= AgentConversationBudget::MAX_HOPS; $hop++) {
            AgentConversationBudget::spend($channel);
        }
    }

    /**
     * @return array{0: Plan, 1: Agent}
     */
    private function boardWithAgent(): array
    {
        $agent = $this->makeAgent();
        $plan = $this->makePlan([], $agent);

        $this->assertNotNull($plan->socialChannels()->first());

        return [$plan, $agent];
    }

    private function board(): Channel
    {
        [$plan] = $this->boardWithAgent();

        return $plan->socialChannels()->first();
    }

    /**
     * A person comments through the generic channel mutation, never `PostPlanActivityMessageAction` —
     * which is exactly what tells the two apart, since they may share a user.
     */
    private function humanComment(Channel $channel, string $content): Message
    {
        return new PostChannelMessageAction(
            channel: $channel,
            author: static::$cachedUser,
            verb: 'agent_reply',
            content: $content,
        )->execute();
    }

    private function comment(Plan $plan, string $content, Users $author): Message
    {
        $message = new PostPlanActivityMessageAction($plan, $content, author: $author)->execute();

        $this->assertNotNull($message, 'The comment was never posted, so this asserts nothing.');

        return $message;
    }
}
