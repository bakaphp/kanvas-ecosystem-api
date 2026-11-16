<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Events\AgentChatResponseEvent;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\NativeChannelDeliveryService;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\PlanBlockedNeedsEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\NotifyPlanOwnerOfCompletedPlanJob;
use Kanvas\NervousSystem\Plan\Jobs\PostStillWorkingUpdateJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\NotifyProjectOwnerOfBlockedPlanJob;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Reporting back where the work was asked for.
 *
 * Plan 26824 finished, the alert landed in the right DM, and the person watching that DM saw nothing —
 * they only knew because the email arrived. A chat thread renders by SESSION (`session_id`, the
 * `ai-chat` type), and the alert had neither, so it sat on the channel outside the conversation. It was
 * also authored by the WORKER, which in a DM with the PM is a stranger interrupting.
 */
final class PlanReportsBackToTheConversationTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testTheDoneReportLandsInTheConversationAsATurnFromThePm(): void
    {
        Notification::fake();

        [$plan, $pm, $session] = $this->plannedInAConversation();

        // Quietly: the observer would dispatch the very job this test runs by hand.
        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        $posted = $this->lastMessageOn($session->channel_id);

        $this->assertNotNull($posted, 'Nothing reached the conversation at all.');
        $this->assertSame(
            $session->uuid,
            $posted->message['session_id'] ?? null,
            'Without the session the chat renders nothing — this is the 26824 symptom.'
        );
        $this->assertSame(
            $pm->user->getId(),
            (int) $posted->users_id,
            'The person is talking to the PM; the worker posting in that DM is a stranger.'
        );
    }

    /**
     * Writing the row is not delivery.
     *
     * The chat renders live off `AgentChatResponseEvent` on the agent's Pusher channel, and
     * `PostChannelMessageAction` broadcasts nothing — so the report was in the thread and simply never
     * appeared, which is indistinguishable from never having been sent.
     */
    public function testTheDoneReportIsPushedIntoTheLiveChat(): void
    {
        Notification::fake();
        Event::fake([AgentChatResponseEvent::class]);

        [$plan, $pm, $session] = $this->plannedInAConversation();

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        Event::assertDispatched(
            AgentChatResponseEvent::class,
            fn (AgentChatResponseEvent $event): bool => $event->agent()->getId() === $pm->getId(),
        );
    }

    /**
     * The chat renders the TRANSCRIPT, not the channel.
     *
     * `agent_conversation_messages` is what the control-center chat reads (`?session=` in its URL is
     * the `agent_conversations` row). A report written only to Social `messages` is in the right room
     * and the wrong record — delivered, and indistinguishable from never sent.
     */
    public function testTheDoneReportIsAppendedToTheChatTranscript(): void
    {
        Notification::fake();

        [$plan, $pm, $session] = $this->plannedInAConversation();

        $conversationId = (string) Str::uuid7();
        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $conversationId,
            'user_id' => $this->human()->getId(),
            'agent_id' => $pm->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'companies_id' => $this->human()->getCurrentCompany()->getId(),
            // ConversationMessageStore keys the conversation by putting the SESSION id in `title`.
            'title' => $session->uuid,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        $turn = DB::connection('intelligence')
            ->table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($turn, 'The chat reads this table — nothing here means nothing rendered.');
        $this->assertSame('assistant', $turn->role);
        $this->assertStringContainsString((string) $plan->title, (string) $turn->content);
    }

    /**
     * One session id can own several conversations, and only one of them is the thread being read.
     *
     * Plan 27699 reported into a 13-message stray while the person watched the 1148-message thread —
     * four rows shared `ai-assist-15776-Users-2-2-11479`, and picking the newest by id chose wrong.
     * The row has to be resolved the way `ConversationMessageStore` resolves it, or the report is filed
     * beside the conversation instead of in it.
     */
    public function testTheReportGoesToTheSameConversationTheAgentsOwnTurnsUse(): void
    {
        Notification::fake();

        [$plan, $pm, $session] = $this->plannedInAConversation();

        $theOneBeingRead = $this->conversationFor($pm, $session->uuid);
        // A later duplicate on the same session — newest by id, and the wrong answer.
        $stray = $this->conversationFor($pm, $session->uuid);

        $this->assertGreaterThan($theOneBeingRead, $stray, 'Precondition: the stray sorts newer.');

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        $this->assertSame(
            1,
            DB::connection('intelligence')->table('agent_conversation_messages')
                ->where('conversation_id', $theOneBeingRead)->count(),
        );
        $this->assertSame(
            0,
            DB::connection('intelligence')->table('agent_conversation_messages')
                ->where('conversation_id', $stray)->count(),
            'Filed beside the conversation instead of in it.',
        );
    }

    /** A thread nobody has open is not somewhere to file a report — no conversation is invented. */
    public function testNoTranscriptTurnIsWrittenWhenThereIsNoConversation(): void
    {
        Notification::fake();

        [$plan, , $session] = $this->plannedInAConversation();

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        $this->assertSame(
            0,
            DB::connection('intelligence')->table('agent_conversations')->where('title', $session->uuid)->count(),
        );
    }

    /**
     * A web conversation has no connector behind it, so the push is a no-op and must stay silent —
     * this is the path every in-app plan takes, and it runs on every completion.
     */
    public function testAWebConversationPushesNothingOutward(): void
    {
        [, , $session] = $this->plannedInAConversation();

        /** @var Channel $channel */
        $channel = Channel::query()->where('id', $session->channel_id)->firstOrFail();

        $this->assertFalse(
            NativeChannelDeliveryService::deliver($channel, 'done', null, null),
            'A plain channel has no connector to push over.',
        );
    }

    /**
     * The Slack destination comes from the session `canal_id`, not the channel slug: the slug is
     * lowercased and Slack ids are case-sensitive, so a slug-derived target posts to the wrong place
     * or nowhere. Thread ts is what keeps the report under the original message.
     */
    public function testTheSlackTargetIsParsedFromTheSessionCanalId(): void
    {
        $parse = new ReflectionMethod(NativeChannelDeliveryService::class, 'slackTargetFromCanalId');

        $this->assertSame(
            ['C09ABCDEF', '1712345678.9012'],
            $parse->invoke(null, 'slack:T01TEAM:C09ABCDEF:1712345678.9012'),
        );
        $this->assertSame(['C09ABCDEF', ''], $parse->invoke(null, 'slack:T01TEAM:C09ABCDEF'));
        $this->assertSame(['', ''], $parse->invoke(null, null), 'No canal_id means no Slack target.');
        $this->assertSame(
            ['', ''],
            $parse->invoke(null, '5551234567@s.whatsapp.net'),
            'A WhatsApp canal_id must not resolve as a Slack channel.',
        );
    }

    /** A block only a person can clear reaches them, even when the plan sits under a project. */
    public function testAHumanBlockOnAProjectPlanReachesTheConversation(): void
    {
        Bus::fake();

        [$plan] = $this->plannedInAConversation();

        $plan->blocked_needs = PlanBlockedNeedsEnum::HUMAN->value;
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->save();

        Bus::assertDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
        Bus::assertNotDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
    }

    /**
     * A missing tool is an operator's problem. Interrupting the person who asked with it pesters
     * somebody who cannot help — it stays with the project digest.
     */
    public function testACapabilityBlockStaysWithTheProjectDigest(): void
    {
        Bus::fake();

        [$plan] = $this->plannedInAConversation();

        $plan->blocked_needs = PlanBlockedNeedsEnum::CAPABILITY->value;
        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->save();

        Bus::assertDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
        Bus::assertNotDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    /** Unclassified blocks keep the old behaviour rather than newly interrupting anyone. */
    public function testAnUnclassifiedBlockKeepsTheProjectDigest(): void
    {
        Bus::fake();

        [$plan] = $this->plannedInAConversation();

        $plan->status = PlanStatusEnum::BLOCKED->value;
        $plan->save();

        Bus::assertDispatched(NotifyProjectOwnerOfBlockedPlanJob::class);
        Bus::assertNotDispatched(NotifyPlanOwnerOfBlockedPlanJob::class);
    }

    /**
     * @return array{0: Plan, 1: Agent, 2: Session}
     */
    private function plannedInAConversation(): array
    {
        $app = app(Apps::class);
        $company = $this->human()->getCurrentCompany();

        $pm = $this->makeAgent();
        $project = new CreateProjectAction(ProjectData::from(
            $app,
            $this->human(),
            $company,
            ['title' => 'Reporting ' . fake()->unique()->lexify('?????'), 'agent_id' => $pm->getId()],
        ))->execute();

        $channel = new CreateChannelAction(
            new ChannelDto(
                apps: $app,
                companies: $company,
                users: $this->human(),
                entity_id: (int) $this->human()->getKey(),
                entity_namespace: Users::class,
                name: 'DM ' . fake()->unique()->lexify('?????'),
                slug: (string) Str::uuid(),
            ),
        )->execute();

        $session = Session::create([
            'uuid' => 'ai-assist-' . fake()->unique()->lexify('?????'),
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'entity_namespace' => Users::class,
            'entity_id' => $this->human()->getId(),
            'agents_id' => $pm->getId(),
            'channel_id' => $channel->getId(),
            'content' => '',
            'user' => ['id' => $this->human()->getId()],
        ]);

        $plan = $this->makePlan([
            'project_id' => $project->getId(),
            'created_by_agent_id' => $pm->getId(),
            'origin_channel_id' => $channel->getId(),
            'origin_session_id' => $session->getId(),
            'origin_users_id' => $this->human()->getId(),
            'status' => PlanStatusEnum::ACTIVE->value,
            'completion_pct' => 100,
        ], $this->makeAgent());

        return [$plan->fresh(), $pm, $session];
    }

    /**
     * The worker finished every task and left the plan open: the PM is woken, and what it answers is
     * for the person who asked — who is in this conversation, not on the project channel.
     */
    public function testThePmsAnswerToAFinishedPlanLandsInTheConversation(): void
    {
        Notification::fake();

        [$plan, $pm, $session] = $this->plannedInAConversation();
        $pm->type->update(['handler' => SalesNeuronAgentStub::class]);

        new WakeAgentForProjectJob(
            $plan->project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'Every task on plan ' . $plan->getId() . ' is finished.',
            wakeAgent: $pm->refresh(),
            reportsOn: $plan,
        )->handle();

        $posted = $this->lastMessageOn($session->channel_id);

        $this->assertNotNull($posted, 'The person who asked never heard the work was done.');
        $this->assertSame($session->uuid, $posted->message['session_id'] ?? null);
        $this->assertSame($pm->user->getId(), (int) $posted->users_id);
    }

    /** The PM already answered in that conversation; the generic "Done" would only repeat it. */
    public function testTheDoneAlertSkipsAConversationThePmAlreadyAnswered(): void
    {
        Notification::fake();

        [$plan, , $session] = $this->plannedInAConversation();
        $plan->rememberFinishWasReported();

        $plan->status = PlanStatusEnum::DONE->value;
        $plan->saveQuietly();

        new NotifyPlanOwnerOfCompletedPlanJob($plan->fresh())->handle();

        $this->assertNull($this->lastMessageOn($session->channel_id));
    }

    public function testASilentChatHearsTheWorkIsStillRunning(): void
    {
        [$plan, $pm, $session] = $this->plannedInAConversation();
        $this->runningTask($plan, startedMinutesAgo: 6);
        $this->saidInTheChat($this->conversationFor($pm, $session->uuid), minutesAgo: 4);

        new PostStillWorkingUpdateJob(app(Apps::class), $session)->handle();

        $posted = $this->lastMessageOn($session->channel_id);

        $this->assertNotNull($posted, 'Three silent minutes and the chat heard nothing.');
        $this->assertStringContainsString('Still working on it', (string) $posted->message['content']);
        $this->assertStringContainsString('6 min so far', (string) $posted->message['content']);
        $this->assertSame($session->uuid, $posted->message['session_id'] ?? null);
        $this->assertSame($pm->user->getId(), (int) $posted->users_id);
    }

    public function testAChatThatSpokeInTheLastThreeMinutesHearsNothing(): void
    {
        [$plan, $pm, $session] = $this->plannedInAConversation();
        $this->runningTask($plan, startedMinutesAgo: 6);
        $this->saidInTheChat($this->conversationFor($pm, $session->uuid), minutesAgo: 1);

        new PostStillWorkingUpdateJob(app(Apps::class), $session)->handle();

        $this->assertNull($this->lastMessageOn($session->channel_id));
    }

    /** Waiting on a person is not "still working": nothing is running. */
    public function testAPlanWithNothingRunningSendsNoUpdate(): void
    {
        [$plan, $pm, $session] = $this->plannedInAConversation();
        $this->makeTask($plan);
        $this->saidInTheChat($this->conversationFor($pm, $session->uuid), minutesAgo: 10);

        new PostStillWorkingUpdateJob(app(Apps::class), $session)->handle();

        $this->assertNull($this->lastMessageOn($session->channel_id));
    }

    public function testTheSweepQueuesOneUpdatePerChatWithWorkRunning(): void
    {
        Bus::fake();

        [$plan, , $session] = $this->plannedInAConversation();
        $this->runningTask($plan, startedMinutesAgo: 6);

        $this->artisan('kanvas:nervous-system:post-still-working-updates')->assertSuccessful();

        Bus::assertDispatched(
            PostStillWorkingUpdateJob::class,
            fn (PostStillWorkingUpdateJob $job): bool => $job->session->getId() === $session->getId()
        );
    }

    /** A factory run is created in progress and never stamps started_at; it is still running. */
    public function testAFactoryRunWithNoStartTimeStillCounts(): void
    {
        Bus::fake();

        [$plan, , $session] = $this->plannedInAConversation();
        $run = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $run->agent_id = $plan->agent_id;
        $run->saveOrFail();

        $this->artisan('kanvas:nervous-system:post-still-working-updates')->assertSuccessful();

        Bus::assertDispatched(
            PostStillWorkingUpdateJob::class,
            fn (PostStillWorkingUpdateJob $job): bool => $job->session->getId() === $session->getId()
        );
    }

    /**
     * The real shape of factory work: the step on the person's plan waits, and its run is a sub-task on a
     * plan of its own. The update names the step and times the run.
     */
    public function testAStepWaitingOnItsFactoryRunIsStillWorking(): void
    {
        [$plan, $pm, $session] = $this->plannedInAConversation();
        $step = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $step->agent_id = $plan->agent_id;
        $step->title = 'Audit the template';
        $step->saveOrFail();

        $run = $this->makeTask($this->makePlan(['title' => 'Factory run'], $plan->agent), TaskStatusEnum::IN_PROGRESS);
        $run->parent_task_id = $step->getId();
        $run->agent_id = $plan->agent_id;
        $run->created_at = now()->subMinutes(7);
        $run->saveOrFail();

        $this->saidInTheChat($this->conversationFor($pm, $session->uuid), minutesAgo: 4);

        new PostStillWorkingUpdateJob(app(Apps::class), $session)->handle();

        $content = (string) $this->lastMessageOn($session->channel_id)?->message['content'];
        $this->assertStringContainsString('"Audit the template"', $content);
        $this->assertStringContainsString('7 min so far', $content);
    }

    /** A coding chat is the agent's own plan, and that chat reports its own progress minute by minute. */
    public function testAnAgentWorkingItsOwnPlanIsNotSwept(): void
    {
        Bus::fake();

        [$plan, , $session] = $this->plannedInAConversation();
        $plan->agent_id = $plan->created_by_agent_id;
        $plan->saveQuietly();
        $this->runningTask($plan, startedMinutesAgo: 6);

        $this->artisan('kanvas:nervous-system:post-still-working-updates')->assertSuccessful();

        Bus::assertNotDispatched(
            PostStillWorkingUpdateJob::class,
            fn (PostStillWorkingUpdateJob $job): bool => $job->session->getId() === $session->getId()
        );
    }

    public function testAStepRunningForHoursIsStuckNotStillWorking(): void
    {
        Bus::fake();

        [$plan, , $session] = $this->plannedInAConversation();
        $this->runningTask($plan, startedMinutesAgo: 240);

        $this->artisan('kanvas:nervous-system:post-still-working-updates')->assertSuccessful();

        Bus::assertNotDispatched(
            PostStillWorkingUpdateJob::class,
            fn (PostStillWorkingUpdateJob $job): bool => $job->session->getId() === $session->getId()
        );
    }

    /** A conversation keyed the way ConversationMessageStore keys one: the session id lives in `title`. */
    private function conversationFor(Agent $agent, string $sessionId): string
    {
        $id = (string) Str::uuid7();

        DB::connection('intelligence')->table('agent_conversations')->insert([
            'id' => $id,
            'user_id' => $this->human()->getId(),
            'agent_id' => $agent->getId(),
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'title' => $sessionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function runningTask(Plan $plan, int $startedMinutesAgo): Task
    {
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $task->agent_id = $plan->agent_id;
        $task->started_at = now()->subMinutes($startedMinutesAgo);
        $task->saveOrFail();

        return $task;
    }

    private function saidInTheChat(string $conversationId, int $minutesAgo): void
    {
        DB::connection('intelligence')->table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $conversationId,
            'user_id' => $this->human()->getId(),
            'agent' => 'Test\\Stub\\Handler',
            'role' => 'assistant',
            'content' => 'On it.',
            'attachments' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'status' => 'completed',
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function lastMessageOn(?int $channelId): ?Message
    {
        /** @var Channel|null $channel */
        $channel = Channel::query()->where('id', $channelId)->first();

        return $channel?->messages()->orderByDesc('messages.id')->first();
    }

    private function human(): Users
    {
        return static::$cachedUser;
    }
}
