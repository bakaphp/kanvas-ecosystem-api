<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\ClaudeAgent\AgentTypes\ClaudeAgent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeWorkerForPlanJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelData;
use Kanvas\Social\Messages\Actions\PostChannelMessageAction;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Models\UsersAssociatedApps;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A comment on a plan's board reaches the agent working it, with nothing to configure.
 *
 * `ReplyToPlanCommentActivity` has done this since it was written, but only where an admin wired a
 * workflow rule for it — and no tenant ever did, so writing on a plan board was silence everywhere.
 */
final class PlanBoardCommentWakeTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testAHumanCommentOnThePlanWakesTheAgentWorkingIt(): void
    {
        Bus::fake();

        [$plan] = $this->plannedBoard();

        $this->humanComment($plan, 'Any update on the crawl?');

        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job): bool => $job->plan->getId() === $plan->getId()
                && $job->reason === WakeAgentForPlanJob::REASON_COMMENT
                && str_contains((string) $job->userMessage, 'Any update on the crawl?')
        );
    }

    /**
     * An agent's plain comment is a note on the record. Waking on it meant the PM's status summaries
     * interrupted the worker for nothing, and gave two agents a way to talk without ever addressing
     * each other.
     */
    public function testAPlainCommentFromAnotherAgentIsANoteAndWakesNobody(): void
    {
        Bus::fake();

        [$plan] = $this->plannedBoard();

        $this->comment($plan, 'Recording that the import finished.', $this->someoneElse());

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /** The assignee talking on its own board must not wake itself — that is an unbounded loop. */
    public function testThePlansOwnAgentDoesNotWakeItself(): void
    {
        Bus::fake();

        [$plan, $agent] = $this->plannedBoard();

        $this->comment($plan, 'Progress: crawled 40 files.', $agent->user);

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * `RespondToAgentMentionListener` wakes an agent named in the text, so waking it here too would
     * make it answer the same comment twice — but only for a SystemUserAgent handler.
     */
    public function testACommentMentioningASystemUserAgentIsLeftToTheMentionPath(): void
    {
        Bus::fake();

        [$plan, $agent] = $this->plannedBoard();
        $agent->type->handler = SystemUserAgent::class;
        $agent->type->saveQuietly();
        $handle = $this->giveHandle($agent->user);

        $this->comment($plan->refresh(), '@' . $handle . ' any update?', $this->someoneElse());

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /** Every agent `hire_agent` creates is a Generic Neuron Agent; the mention path answers those too. */
    public function testACommentMentioningAHiredNeuronAgentIsLeftToTheMentionPath(): void
    {
        Bus::fake();

        [$plan, $agent] = $this->plannedBoard();
        $agent->type->handler = KanvasGenericNeuronAgent::class;
        $agent->type->saveQuietly();
        $handle = $this->giveHandle($agent->user);

        $this->comment($plan->refresh(), '@' . $handle . ' any update?', $this->someoneElse());

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * A hosted agent is not Neuron-shaped, so `RespondToMentionJob` would return without a trace.
     * Deferring to it would leave the mention reaching nobody — worse than not naming the agent.
     */
    public function testACommentMentioningANonNeuronAgentStillWakesItHere(): void
    {
        Bus::fake();

        [$plan, $agent] = $this->plannedBoard();
        $agent->type->handler = ClaudeAgent::class;
        $agent->type->saveQuietly();
        $handle = $this->giveHandle($agent->user);

        $this->comment($plan->refresh(), '@' . $handle . ' any update?', $this->someoneElse());

        Bus::assertDispatched(WakeAgentForPlanJob::class);
    }

    /** A person reaches the assignee whoever else they tag — they should not have to know handles. */
    public function testAHumanCommentMentioningSomeoneElseStillWakesTheAssignee(): void
    {
        Bus::fake();

        [$plan] = $this->plannedBoard();

        $this->humanComment($plan, '@somebodyelse can you confirm? Then we ship.');

        Bus::assertDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * The loop that ran plan 20104 seventy-eight times.
     *
     * `PersistChatTurnToSocialAction` writes every wake's own PROMPT onto this same channel, authored
     * by the plan's OWNER — so it clears the author guard, reads as a fresh comment, and wakes the
     * agent, which persists another prompt. Only the wake budget stopped it.
     */
    public function testAWakesOwnPromptEchoedOntoTheBoardDoesNotWakeAgain(): void
    {
        [$plan] = $this->plannedBoard();
        $channel = $plan->socialChannels()->first();

        Bus::fake();

        $turn = new PostChannelMessageAction(
            channel: $channel,
            author: $this->human(),
            verb: 'ai-chat',
            content: '[NS:continuation] plan_id=' . $plan->getId() . ' verdict=extend',
            extraPayload: ['session_id' => 'sess-' . fake()->unique()->lexify('?????')],
        )->execute();

        $this->assertNotNull($turn);
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
        Bus::assertNotDispatched(WakeWorkerForPlanJob::class);
    }

    /**
     * A worker woken without the board tools reports it cannot move a task or answer on the board,
     * and blocks itself — which is what plan 20104's task recorded as its blocker.
     */
    public function testADelegatedPlanWakesTheWorkerWithItsBoardTools(): void
    {
        Bus::fake();

        [$plan] = $this->plannedBoard();
        $plan->project_id = $this->project()->getId();
        $plan->saveQuietly();

        $this->humanComment($plan->refresh(), 'Any update?');

        Bus::assertDispatched(WakeWorkerForPlanJob::class);
        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /** Every message in the company passes through this listener; only a plan's board may wake one. */
    public function testACommentOnAChannelThatCarriesNoPlanWakesNobody(): void
    {
        $channel = new CreateChannelAction(
            new ChannelData(
                apps: $this->app(),
                companies: $this->human()->getCurrentCompany(),
                users: $this->human(),
                entity_id: (string) $this->human()->getId(),
                entity_namespace: Users::class,
                name: 'Chatter ' . fake()->unique()->lexify('?????'),
                description: 'Not a plan board',
                slug: (string) Str::uuid(),
            ),
        )->execute();

        Bus::fake();

        new PostChannelMessageAction(
            channel: $channel,
            author: $this->human(),
            verb: 'agent_reply',
            content: 'Just talking.',
        )->execute();

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    /**
     * The Activities channel is not built here — `PlanObserver::created` makes it, which is the same
     * board a real plan gets.
     *
     * @return array{0: Plan, 1: Agent}
     */
    private function plannedBoard(): array
    {
        $agent = $this->makeAgent();
        $plan = $this->makePlan([], $agent);

        $this->assertNotNull(
            $plan->socialChannels()->first(),
            'A plan with no Activities channel cannot be commented on at all.'
        );

        return [$plan, $agent];
    }

    /**
     * A distinct identity that can actually post: the loop guard compares the author against the
     * assignee's own user, and `MessageObserver` counts every message against the author's app
     * profile — a user without one cannot write at all.
     */
    private function someoneElse(): Users
    {
        $user = Users::factory()->create();
        $this->giveHandle($user);

        return $user;
    }

    /**
     * A handle-safe `displayname`, which is what makes a user mentionable: `ParseMessageMentionsAction`
     * matches `@token`, so the seeded "Firstname Lastname" profiles cannot be mentioned at all.
     */
    private function giveHandle(Users $user): string
    {
        $handle = 'handle' . fake()->unique()->lexify('?????');

        UsersAssociatedApps::updateOrCreate(
            [
                'users_id' => $user->getId(),
                'apps_id' => $this->app()->getId(),
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

    private function project(): Project
    {
        return new CreateProjectAction(ProjectData::from(
            $this->app(),
            $this->human(),
            $this->human()->getCurrentCompany(),
            [
                'title' => 'Board ' . fake()->unique()->lexify('?????'),
                'agent_id' => $this->makeAgent()->getId(),
            ],
        ))->execute();
    }

    /**
     * A person comments through the generic channel mutation, never `PostPlanActivityMessageAction` —
     * which is what tells a person's comment from an agent's, since they may share a user.
     */
    private function humanComment(Plan $plan, string $content): void
    {
        new PostChannelMessageAction(
            channel: $plan->socialChannels()->first(),
            author: static::$cachedUser,
            verb: 'agent_reply',
            content: $content,
        )->execute();
    }

    private function comment(Plan $plan, string $content, Users $author): void
    {
        // The action swallows its own failures, so an un-posted comment would read as "no wake".
        $this->assertNotNull(
            new PostPlanActivityMessageAction($plan, $content, author: $author)->execute(),
            'The comment was never posted, so this asserts nothing about waking.'
        );
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function human(): Users
    {
        return static::$cachedUser;
    }
}
