<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\FactoryServeClient;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DeliverCodingChatTurnAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\OpenCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatMessageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\ReportFactoryProblemsAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryHealth;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\WatchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CheckoutHolderNotice;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CodingChatCheckouts;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Actions\Chat\RunCodingChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\Users\Models\Users;
use Override;
use Tests\Intelligence\Integration\Harness\Concerns\CreatesTaskSessions;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A coding chat: the agent's conversation drives one opencode session in the factory checkout, turn by
 * turn, with no model of Kanvas's own reading the messages.
 */
class CodingChatTest extends TestCase
{
    use CreatesTaskSessions;
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testTheKernelHandsAChatModeAgentsMessageToTheFactoryInsteadOfAModel(): void
    {
        [$agent, $chat] = $this->chatAgent();

        $reply = new AgentChatKernel(
            agent: $agent,
            session: $chat,
            message: 'make the button blue',
            user: $this->user(),
            persistConversation: false,
        )->execute();

        $this->assertStringContainsString('/repo', $reply);
        $this->assertNull(AgentTaskSession::liveChatFor($agent, $chat));
    }

    /**
     * The chat page renders and reloads from the transcript, not the Social channel. Without the turn
     * there, a coding chat looked fine live and was blank after a refresh — and later turn deliveries
     * had no conversation to be appended to.
     */
    public function testEveryTurnIsRecordedInTheTranscriptTheChatRendersFrom(): void
    {
        [$agent, $chat] = $this->chatAgent();

        $reply = $this->say($agent, $chat, '/status');

        $conversation = DB::connection('intelligence')->table('agent_conversations')
            ->where('agent_id', $agent->getId())
            ->where('title', $chat->uuid)
            ->first();
        $this->assertNotNull($conversation);

        $rows = DB::connection('intelligence')->table('agent_conversation_messages')
            ->where('conversation_id', $conversation->id)
            ->orderBy('id')
            ->get(['role', 'content'])
            ->map(fn (object $row): array => [$row->role, $row->content])
            ->all();

        $this->assertSame([['user', '/status'], ['assistant', $reply]], $rows);
    }

    public function testRepoAloneOpensTheCheckoutAndWaitsForTheFirstInstruction(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());

        $reply = $this->say($agent, $chat, '/repo Quorum-app');

        $live = AgentTaskSession::liveChatFor($agent, $chat);
        $this->assertStringContainsString('ready for your first instruction', $reply);
        $this->assertSame('Quorum-app', $live?->repo_slug);
        Queue::assertPushed(LaunchCodingChatJob::class, fn (LaunchCodingChatJob $job): bool => $job->instruction === '');
    }

    public function testAPlainMessageOpensTheAgentsDefaultRepository(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());
        $agent->set(CodingChatSettingEnum::DEFAULT_REPO->value, 'factory-sandbox');

        $reply = $this->say($agent, $chat, 'make the button blue');

        $this->assertStringContainsString('Opening **factory-sandbox**', $reply);
        $this->assertSame('factory-sandbox', AgentTaskSession::liveChatFor($agent, $chat)?->repo_slug);
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => $job->instruction === 'make the button blue'
        );
    }

    public function testTheFirstMessageInACheckoutOpenedWithoutAnInstructionCreatesTheSession(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE);
        $live->external_session_id = null;
        $live->saveOrFail();
        $transport = $this->transport(['session' => ['id' => 'ses_new']]);

        $this->say(
            $agent,
            $chat,
            'fix the login spinner',
            $transport
        );

        $this->assertSame(['POST', '/api/session'], array_slice($transport->requests[0], 0, 2));
        $this->assertContains(
            ['POST', '/api/session/ses_new/prompt', ['text' => 'fix the login spinner']],
            $transport->requests
        );
        $this->assertSame('ses_new', $live->refresh()->external_session_id);
    }

    public function testAPathIsNotARepository(): void
    {
        [$agent, $chat] = $this->chatAgent();

        $reply = $this->say($agent, $chat, '/repo ../../etc fix it');

        $this->assertStringContainsString('Which repository?', $reply);
        $this->assertNull(AgentTaskSession::liveChatFor($agent, $chat));
    }

    public function testRepoOpensAChatSessionOnTheMachineAndQueuesTheLaunch(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $machine = $this->machine();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $machine->getId());

        $reply = $this->say($agent, $chat, '/repo Quorum-app fix the login spinner');

        $live = AgentTaskSession::liveChatFor($agent, $chat);
        $this->assertNotNull($live);
        $this->assertStringContainsString('Opening **Quorum-app**', $reply);
        $this->assertSame('Quorum-app', $live->repo_slug);
        $this->assertSame($machine->getId(), $live->agent_machine_id);
        $this->assertSame(HarnessStatusEnum::STARTING->value, $live->status);
        $this->assertNull($live->workspace_path, 'the reaper must never see the factory checkout');
        $this->assertSame(OpenCodingChatAction::PLAN_TYPE, Plan::find($live->plan_id)?->plan_type);
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => $job->sessionId === $live->getId()
                && $job->instruction === 'fix the login spinner'
        );
    }

    /**
     * The conversation's channel only exists once the kernel persists the first turn, after `/repo` ran.
     * Without binding it later the plan has no origin, and every reply of the chat is silently dropped.
     */
    public function testAChatOpenedByTheFirstMessageStillDeliversOnceItsChannelExists(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());

        $this->say($agent, $chat, '/repo Quorum-app fix the login spinner');

        $live = AgentTaskSession::liveChatFor($agent, $chat);
        $plan = Plan::findOrFail($live->plan_id);
        $this->assertNull($plan->origin_channel_id);

        $channelId = (int) $plan->socialChannels()->value('channels.id');
        $chat->channel_id = $channelId;
        $chat->saveOrFail();

        new PostCodingChatMessageAction($live->refresh(), 'working')->execute();

        $this->assertSame($channelId, (int) $plan->refresh()->origin_channel_id);
    }

    public function testRepoSwitchesByClosingTheCurrentCheckoutFirst(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());
        $current = $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE);
        $current->external_session_id = null;
        $current->saveOrFail();

        $same = $this->say($agent, $chat, '/repo Quorum-app');
        $reply = $this->say($agent, $chat, '/repo Junkmaxy-app do something');

        $this->assertSame('Already working in **Quorum-app**.', $same);
        $this->assertStringContainsString('Chat closed', $reply);
        $this->assertStringContainsString('Opening **Junkmaxy-app**', $reply);
        $this->assertFalse($current->refresh()->isLive());
        $this->assertSame('Junkmaxy-app', AgentTaskSession::liveChatFor($agent, $chat)?->repo_slug);
    }

    /**
     * Real case: "Go to documents, and then projects and then open the Quorum-app" reached the agent as
     * a steer, and it announced it would go and work in that folder itself — outside its checkout.
     */
    public function testAPlainLanguageRequestToOpenAnotherCheckoutSwitchesToIt(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());
        $current = $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE);
        $current->external_session_id = null;
        $current->saveOrFail();

        $reply = $this->say(
            $agent,
            $chat,
            'Go to documents, and then projects and then open the Junkmaxy-app',
            checkouts: $this->checkouts($agent, ['Junkmaxy-app', 'Quorum-app']),
        );

        $this->assertStringContainsString('Opening **Junkmaxy-app**', $reply);
        $this->assertFalse($current->refresh()->isLive());
        $this->assertSame('Junkmaxy-app', AgentTaskSession::liveChatFor($agent, $chat)?->repo_slug);
    }

    public function testMentioningAnotherCheckoutWithoutAskingToOpenItStillReachesTheAgent(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);

        $reply = $this->say(
            $agent,
            $chat,
            'use the button from Junkmaxy-app as the reference',
            $this->transport(),
            $this->checkouts($agent, ['Junkmaxy-app', 'Quorum-app']),
        );

        $this->assertSame('Added to the current turn.', $reply);
        $this->assertSame('Quorum-app', AgentTaskSession::liveChatFor($agent, $chat)?->repo_slug);
    }

    public function testACheckoutIsNamedOnlyAsAWholeWordAndOnlyWhenItIsTheOnlyOne(): void
    {
        $checkouts = $this->checkouts($this->makeAgent(), ['Quorum-app', 'Junkmaxy-app']);

        $this->assertSame('Quorum-app', $checkouts->namedIn('please open quorum-app'));
        $this->assertNull($checkouts->namedIn('open Quorum-app-old'));
        $this->assertNull($checkouts->namedIn('open Quorum-app and Junkmaxy-app'));
    }

    public function testAMessageBetweenTurnsStartsTheNextTurnInTheSameSession(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE);
        $transport = $this->transport();

        $reply = $this->say(
            $agent,
            $chat,
            'now add a test',
            $transport
        );

        $this->assertSame('⏳ On it.', $reply);
        $this->assertSame(HarnessStatusEnum::RUNNING->value, $live->refresh()->status);
        $this->assertSame(
            ['POST', '/api/session/ses_chat/prompt', ['text' => 'now add a test', 'delivery' => 'queue']],
            $transport->requests[0]
        );
        Queue::assertPushed(PollHarnessSessionJob::class, fn (PollHarnessSessionJob $job): bool => $job->sessionId === $live->getId());
    }

    public function testAMessageDuringATurnSteersIt(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);
        $transport = $this->transport();

        $reply = $this->say(
            $agent,
            $chat,
            'use the existing hook',
            $transport
        );

        $this->assertSame('Added to the current turn.', $reply);
        $this->assertSame(
            ['POST', '/api/session/ses_chat/prompt', ['text' => 'use the existing hook', 'delivery' => 'steer']],
            $transport->requests[0]
        );
    }

    public function testYesAnswersThePendingPermissionOnce(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $this->liveChat($agent, $chat, HarnessStatusEnum::AWAITING_PERMISSION);
        $transport = $this->transport([
            'permission' => ['data' => [['id' => 'per_1', 'action' => 'bash', 'resources' => ['yarn test']]]],
        ]);

        $reply = $this->say(
            $agent,
            $chat,
            'Yes',
            $transport
        );

        $this->assertSame('Allowed once.', $reply);
        $this->assertContains(
            ['POST', '/api/session/ses_chat/permission/per_1/reply', ['decision' => 'once']],
            $transport->requests
        );
        $this->assertSame(
            HarnessStatusEnum::RUNNING->value,
            AgentTaskSession::liveChatFor($agent, $chat)?->status,
            'the next message must reach the turn, not ask about a permission already answered'
        );
    }

    public function testAMessageAfterThePermissionWasSettledReachesTheAgentInsteadOfBeingDropped(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::AWAITING_PERMISSION);
        $transport = $this->transport();

        $reply = $this->say(
            $agent,
            $chat,
            'how are we doing?',
            $transport
        );

        $this->assertSame('Added to the current turn.', $reply);
        $this->assertContains(
            ['POST', '/api/session/ses_chat/prompt', ['text' => 'how are we doing?', 'delivery' => 'steer']],
            $transport->requests
        );
        $this->assertSame(HarnessStatusEnum::RUNNING->value, $live->refresh()->status);
    }

    public function testFactoryHealthReportsCrashesAndWhatStoppedButNotWhatStarted(): void
    {
        $before = new FactoryHealth(
            at: 100,
            ios: ['iPhone 18 Pro'],
            android: ['emulator-5554' => 'device'],
            emulators: ['Medium_Phone_API_37.0'],
            metro: true,
        );
        $after = new FactoryHealth(
            at: 160,
            ios: [],
            android: ['emulator-5554' => 'offline', 'emulator-5556' => 'device'],
            emulators: [],
            metro: false,
            crashes: [['process' => 'adb', 'count' => 3, 'last' => 150, 'reason' => 'EXC_CRASH SIGABRT']],
        );

        $this->assertSame([
            '💥 **adb** crashed 3 times (EXC_CRASH SIGABRT)',
            '📱 iOS simulator **iPhone 18 Pro** shut down',
            '🤖 Android emulator **Medium_Phone_API_37.0** stopped',
            '🤖 **emulator-5554** is now offline',
            '⚡ Metro stopped: the app cannot load its JavaScript until it is started again',
        ], $after->problemsSince($before));

        $this->assertSame(
            [],
            $before->problemsSince(new FactoryHealth(at: 40, android: [])),
            'things starting is the agent\'s news, not a problem'
        );
        $this->assertSame(['💥 **adb** crashed 3 times (EXC_CRASH SIGABRT)'], $after->problemsSince(null));
    }

    public function testAdbGoingDownIsOneProblemNotOnePerDevice(): void
    {
        $before = new FactoryHealth(at: 100, android: ['emulator-5554' => 'device', 'emulator-5556' => 'device']);
        $after = new FactoryHealth(at: 160, android: null);

        $this->assertSame(
            ['🤖 adb stopped answering, so no Android device can be reached'],
            $after->problemsSince($before)
        );
    }

    public function testFactoryProblemsMidTurnAreToldToTheAgentToo(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);
        $transport = $this->transport();
        $crashing = new FactoryHealth(
            at: 200,
            crashes: [['process' => 'Android emulator', 'count' => 20, 'last' => 190, 'reason' => '']],
        );

        $problems = new ReportFactoryProblemsAction($live, $crashing, $transport)->execute();

        $this->assertSame(['💥 **Android emulator** crashed 20 times'], $problems);
        $this->assertCount(1, $transport->requests);
        $this->assertSame('/api/session/ses_chat/prompt', $transport->requests[0][1]);
        $this->assertSame('steer', $transport->requests[0][2]['delivery']);
        $this->assertStringContainsString('Android emulator** crashed 20 times', $transport->requests[0][2]['text']);
        $this->assertSame(200, ReportFactoryProblemsAction::since($live), 'the next look starts where this one ended');

        $quiet = new ReportFactoryProblemsAction($live, new FactoryHealth(at: 260), $transport)->execute();

        $this->assertSame([], $quiet);
        $this->assertCount(1, $transport->requests, 'nothing new, nothing said');
    }

    public function testBetweenTurnsOnlyThePersonIsToldSoNoTurnIsStarted(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE);
        $transport = $this->transport();
        new ReportFactoryProblemsAction($live, new FactoryHealth(at: 100, metro: true), $transport)->execute();

        $problems = new ReportFactoryProblemsAction($live, new FactoryHealth(at: 160, metro: false), $transport)->execute();

        $this->assertSame(['⚡ Metro stopped: the app cannot load its JavaScript until it is started again'], $problems);
        $this->assertSame([], $transport->requests);
    }

    public function testEveryDeliveredTurnKeepsTheFactoryWatched(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);

        new DeliverCodingChatTurnAction($live, new HarnessTick(
            status: HarnessStatusEnum::IDLE,
            usage: new HarnessUsage(),
            closedTurns: 1,
            turnReply: 'done',
        ))->execute();

        Queue::assertPushed(WatchCodingChatJob::class, fn (WatchCodingChatJob $job): bool => $job->sessionId === $live->getId());
    }

    public function testTheWatchEndsWithTheChat(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $closed = $this->liveChat($agent, $chat, HarnessStatusEnum::COMPLETED);

        new WatchCodingChatJob(app(Apps::class), $closed->getId())->handle();

        Queue::assertNotPushed(WatchCodingChatJob::class);
    }

    public function testATaskForAFactoryAgentIsHandedToTheFactoryAndWaitsOnIt(): void
    {
        Queue::fake();
        $akira = $this->factoryAgent();
        $task = $this->factoryTask($akira, 'Build the login screen in SecondLook-app');

        new RunTaskWorkerJob($task)->handle();

        $this->assertSame(TaskStatusEnum::WAITING->value, $task->refresh()->status, 'not done before the work is');
        $run = $this->factoryRunOf($task);
        $this->assertSame('SecondLook-app', $run->repo_slug, 'the checkout the task names wins over the default');
        $this->assertTrue($run->isTaskRun());
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => $job->sessionId === $run->getId()
                && str_contains($job->instruction, 'Task: Build the login screen in SecondLook-app')
                && str_contains($job->instruction, 'START YOUR REPLY WITH THE WORD BLOCKED')
        );
    }

    public function testTheFactoryRunsAnswerBecomesTheResultOfTheTaskThatStartedIt(): void
    {
        Queue::fake();
        $akira = $this->factoryAgent();
        $task = $this->factoryTask($akira, 'Build the login screen');
        $run = $this->startFactoryRun($task);
        $answer = 'Opened https://github.com/mctekk/Quorum-app/pull/12 against develop; tsc and lint pass.';

        $this->finishTurn($run, HarnessStatusEnum::IDLE, $answer);

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $run->refresh()->status, 'a task run closes with its turn');
        $this->assertSame(TaskStatusEnum::DONE->value, $run->task()->firstOrFail()->status);

        new RunTaskWorkerJob($task->refresh(), 'Task #1 is now done.')->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::DONE->value, $task->status);
        $this->assertSame($answer, $task->result['worker_summary']);
        $this->assertSame(
            1,
            AgentTaskSession::query()->where('agent_id', $akira->getId())->count(),
            'resuming the task must not start the same work in the factory again'
        );
        Queue::assertPushed(WakeAgentForPlanJob::class);
    }

    public function testAFactoryRunThatFailsBlocksTheTaskThatStartedIt(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);

        $this->finishTurn(
            run: $run,
            status: HarnessStatusEnum::FAILED,
            reply: null,
            error: 'provider.auth (HTTP 401): bad key',
        );
        new RunTaskWorkerJob($task->refresh(), 'Task #1 is now blocked.')->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('provider.auth (HTTP 401)', (string) $task->blocked_reason);
    }

    public function testAFactoryRunThatSaysBlockedBlocksTheTaskEvenThoughItFinished(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Run eas init');
        $run = $this->startFactoryRun($task);

        $this->finishTurn($run, HarnessStatusEnum::IDLE, 'BLOCKED: eas init needs Max\'s Expo access.');
        new RunTaskWorkerJob($task->refresh(), 'Task #1 is now done.')->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('Expo access', (string) $task->blocked_reason);
    }

    public function testATaskWithNoCheckoutToWorkInIsBlockedWithWhatToDo(): void
    {
        Queue::fake();
        $akira = $this->factoryAgent(defaultRepo: null);
        $task = $this->factoryTask($akira, 'Fix the spinner');

        new RunTaskWorkerJob($task)->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('CODING_CHAT_DEFAULT_REPO', (string) $task->blocked_reason);
        Queue::assertNotPushed(LaunchCodingChatJob::class);
    }

    public function testAssigningATaskToAFactoryAgentHandsItToTheWorkerOnce(): void
    {
        Queue::fake();
        $akira = $this->factoryAgent();
        $task = $this->factoryTask($akira, 'Build the login screen', inProject: true);

        new WakeAgentForTaskJob($task)->handle();

        Queue::assertPushed(RunTaskWorkerJob::class, fn (RunTaskWorkerJob $job): bool => $job->task->is($task));

        Queue::fake();
        $this->startFactoryRun($task);
        new WakeAgentForTaskJob($task->refresh())->handle();

        Queue::assertNotPushed(RunTaskWorkerJob::class);
    }

    public function testTheFactoryClientReportsWhoHoldsACheckoutInUse(): void
    {
        try {
            $this->factory(json_encode([
                'error' => 'Quorum-app is in use by holder-uuid; release it first',
                'holder' => 'holder-uuid',
            ]))->open('Quorum-app', 'owner-1');
            $this->fail('Expected the held checkout to be refused');
        } catch (CheckoutInUseException $e) {
            $this->assertSame('holder-uuid', $e->holder);
        }
    }

    public function testACheckoutOpenInAnotherConversationOfYoursLinksToItAndOffersTake(): void
    {
        config(['kanvas.app.frontend_url' => 'https://app.kanvas.test']);
        [$agent, $first] = $this->chatAgent();
        $holder = $this->liveChat(
            agent: $agent,
            chat: $first,
            status: HarnessStatusEnum::IDLE,
            userId: $this->user()->getId(),
        );
        $conversationId = new KanvasConversationStore()->conversationForSession(
            userId: $this->user()->getId(),
            sessionId: (string) $first->uuid,
            agentId: $agent->getId(),
            appsId: $agent->apps_id,
            companiesId: $agent->companies_id,
        );
        $refused = $this->refusedChat($agent, $this->user()->getId());

        $message = new CheckoutHolderNotice($refused, $holder->uuid)->message();

        $this->assertStringContainsString('already open in another conversation of yours', $message);
        $this->assertStringContainsString(
            'control-center/assistants?assistant=' . $agent->getId() . '&session=' . $conversationId,
            $message
        );
        $this->assertStringContainsString('Send `/take Quorum-app` here', $message);
        $this->assertStringContainsString('branch `opencode/20261110-1200`', $message);
        $this->assertStringNotContainsString($holder->uuid, $message, 'a session id means nothing to a person');
    }

    public function testACheckoutHeldBySomeoneElseNamesThemAndOffersNoTake(): void
    {
        [$agent, $first] = $this->chatAgent();
        $someoneElse = $this->makeAgent()->user;
        $holder = $this->liveChat(
            agent: $agent,
            chat: $first,
            status: HarnessStatusEnum::IDLE,
            userId: $someoneElse->getId(),
        );

        $message = new CheckoutHolderNotice($this->refusedChat($agent, $this->user()->getId()), $holder->uuid)->message();

        $this->assertStringContainsString($someoneElse->fullName() . '\'s conversation with ' . $agent->name, $message);
        $this->assertStringNotContainsString('/take', $message);
    }

    public function testACheckoutHeldByAWorkflowTaskNamesTheTask(): void
    {
        Queue::fake();
        $akira = $this->factoryAgent();
        $run = $this->startFactoryRun($this->factoryTask($akira, 'Build the login screen'));

        $message = new CheckoutHolderNotice($this->refusedChat($akira, $this->user()->getId()), $run->uuid)->message();

        $this->assertStringContainsString('busy with the task "Build the login screen"', $message);
        $this->assertStringNotContainsString('/take', $message);
    }

    public function testACheckoutHeldOutsideThisKanvasSaysSoWithoutGuessing(): void
    {
        [$agent] = $this->chatAgent();

        $message = new CheckoutHolderNotice($this->refusedChat($agent, $this->user()->getId()), 'not-a-session-here')->message();

        $this->assertStringContainsString('in use outside this Kanvas', $message);
    }

    public function testTakeMovesTheCheckoutHereFromYourOtherConversation(): void
    {
        Queue::fake();
        [$agent, $first] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());
        $holder = $this->liveChat(
            agent: $agent,
            chat: $first,
            status: HarnessStatusEnum::IDLE,
            userId: $this->user()->getId(),
        );
        $here = $this->chatSession($agent);

        $reply = $this->say($agent, $here, '/take quorum-app fix the spinner');

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $holder->refresh()->status);
        $this->assertStringContainsString('Opening **quorum-app**', $reply);
        $opened = AgentTaskSession::liveChatFor($agent, $here);
        $this->assertNotNull($opened);
        Queue::assertPushed(LaunchCodingChatJob::class, fn (LaunchCodingChatJob $job): bool => $job->sessionId === $opened->getId());
    }

    public function testTakeNeverTakesSomeoneElsesConversation(): void
    {
        Queue::fake();
        [$agent, $first] = $this->chatAgent();
        $holder = $this->liveChat(
            agent: $agent,
            chat: $first,
            status: HarnessStatusEnum::IDLE,
            userId: $this->makeAgent()->user->getId(),
        );

        $reply = $this->say($agent, $this->chatSession($agent), '/take Quorum-app');

        $this->assertStringContainsString('None of your other conversations has **Quorum-app** open', $reply);
        $this->assertTrue($holder->refresh()->isLive());
        Queue::assertNotPushed(LaunchCodingChatJob::class);
    }

    public function testTheFactoryPersonaIsTheAgentsOwnSoulAndInstructionsNeverItsTypes(): void
    {
        [$agent] = $this->chatAgent();
        $agent->type->soul = 'You delegate coding work to a runtime and report back what changed.';
        $agent->type->saveOrFail();
        $agent->soul = '  You are Akira, a calm senior React Native engineer.  ';
        $agent->instructions = 'Run tsc before you say it is done.';
        $agent->saveOrFail();

        $this->assertSame(
            "You are Akira, a calm senior React Native engineer.\n\nRun tsc before you say it is done.",
            CodingChatSettingEnum::personaFor($agent->refresh())
        );

        $agent->soul = null;
        $agent->instructions = '   ';
        $agent->saveOrFail();

        $this->assertNull(
            CodingChatSettingEnum::personaFor($agent->refresh()),
            'no soul of its own keeps the factory persona, not the orchestrator text of its type'
        );
    }

    public function testTheFactoryClientSendsTheNameAndPersonaAsOneEncodedArgumentEach(): void
    {
        $client = $this->factory(json_encode([
            'directory' => '/Users/joseceron/Documents/Projects/Quorum-app',
            'branch' => 'opencode/20261110-1200',
            'port' => '4097',
            'password' => 'pw',
            'provider' => 'kanvas-openrouter',
            'model' => 'deepseek/deepseek-v4.1-flash-1017',
        ]));
        $persona = "You are Akira.\nSay \"it's done\" only after tsc; never `rm -rf`.";

        $client->open(
            repo: 'Quorum-app',
            owner: 'owner-1',
            name: 'Akira',
            persona: $persona,
        );

        $this->assertSame(
            "cd && 'Documents/Projects/opencode-factory/scripts/serve.sh' 'open' 'Quorum-app' 'owner-1' "
            . "'--name' '" . base64_encode('Akira') . "' '--persona' '" . base64_encode($persona) . "' 2>/dev/null",
            $client->commands[0]
        );
    }

    public function testTheFactoryClientReadsAHealthSnapshot(): void
    {
        $client = $this->factory(json_encode([
            'at' => 1794348109,
            'ios' => ['iPhone 18 Pro'],
            'android' => [],
            'emulators' => ['Medium_Phone_API_37.0'],
            'metro' => false,
            'crashes' => [['process' => 'adb', 'count' => 26, 'last' => 1794347264, 'reason' => 'EXC_CRASH SIGABRT']],
        ]));

        $health = $client->health(1794340000);

        $this->assertSame(1794348109, $health->at);
        $this->assertSame(['Medium_Phone_API_37.0'], $health->emulators);
        $this->assertSame(26, $health->crashes[0]['count']);
        $this->assertStringEndsWith("'health' '1794340000' 2>/dev/null", $client->commands[0]);
    }

    public function testAnythingButADecisionLeavesThePermissionPending(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $this->liveChat($agent, $chat, HarnessStatusEnum::AWAITING_PERMISSION);
        $transport = $this->transport([
            'permission' => ['data' => [['id' => 'per_1', 'action' => 'bash', 'resources' => ['yarn test']]]],
        ]);

        $reply = $this->say(
            $agent,
            $chat,
            'what does it do?',
            $transport
        );

        $this->assertStringContainsString('Reply **yes**, **always** or **no**', $reply);
        $this->assertSame([], array_filter($transport->requests, fn (array $request): bool => $request[0] === 'POST'));
    }

    public function testAFinishedTurnParksTheChatAndMovesPastItsIdle(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);

        new DeliverCodingChatTurnAction($live, new HarnessTick(
            status: HarnessStatusEnum::FAILED,
            usage: new HarnessUsage(),
            error: 'provider.auth (HTTP 401): bad key',
            closedTurns: 2,
            turnReply: null,
        ))->execute();

        $live->refresh();
        $this->assertSame(HarnessStatusEnum::IDLE->value, $live->status, 'a failed turn must not end the conversation');
        $this->assertSame(2, $live->turn_offset);
        $this->assertTrue($live->isLive());
    }

    public function testTheSweeperLeavesAChatWaitingOnAPersonAloneForADay(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $waiting = $this->liveChat(
            agent: $agent,
            chat: $chat,
            status: HarnessStatusEnum::IDLE,
            heartbeat: Carbon::now()->subHours(2),
        );
        $abandoned = $this->liveChat(
            agent: $agent,
            chat: $this->chatSession($agent),
            status: HarnessStatusEnum::IDLE,
            heartbeat: Carbon::now()->subHours(25),
        );

        $this->artisan('kanvas:coding:sweep-sessions', ['--dry-run' => true])
            ->doesntExpectOutputToContain($waiting->uuid)
            ->expectsOutputToContain($abandoned->uuid . ' · idle · Closed after 24 hours without a message.')
            ->assertSuccessful();
    }

    public function testAChatDoesNotCountAgainstTheCodingJobCap(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);

        $jobs = AgentTaskSession::query()
            ->fromApp(app(Apps::class))
            ->fromCompany($agent->company)
            ->live()
            ->whereNull('chat_session_id')
            ->where('agent_id', $agent->getId())
            ->count();

        $this->assertSame(0, $jobs);
    }

    public function testTheFactoryClientReadsTheLastJsonLineAndRaisesItsError(): void
    {
        $opened = $this->factory("baseline noise\n" . json_encode([
            'repo' => 'Quorum-app',
            'directory' => '/Users/joseceron/Documents/Projects/Quorum-app',
            'branch' => 'opencode/20261110-1200',
            'port' => '4097',
            'password' => 'pw',
            'provider' => 'kanvas-openrouter',
            'model' => 'deepseek/deepseek-v4.1-flash-1017',
        ]))->open('Quorum-app', 'owner-1');

        $this->assertSame(4097, $opened['port']);
        $this->assertSame('opencode/20261110-1200', $opened['branch']);

        $this->expectException(HarnessTransportException::class);
        $this->expectExceptionMessage('Quorum-app is in use by someone-else; release it first');

        $this->factory('{"error":"Quorum-app is in use by someone-else; release it first"}')->open('Quorum-app', 'owner-1');
    }

    public function testTheFactoryClientQuotesEveryArgument(): void
    {
        $client = $this->factory('{"released":"true"}');
        $client->release('Quorum-app', 'owner-1');

        $this->assertSame(
            "cd && 'Documents/Projects/opencode-factory/scripts/serve.sh' 'release' 'Quorum-app' 'owner-1' 2>/dev/null",
            $client->commands[0]
        );
    }

    /**
     * @return array{0: Agent, 1: Session}
     */
    private function chatAgent(): array
    {
        $agent = $this->makeAgent();
        $agent->set(CodingChatSettingEnum::MODE->value, true);

        return [$agent, $this->chatSession($agent)];
    }

    /**
     * An agent in factory mode on a machine, with its checkouts already listed so nothing reaches SSH.
     */
    private function factoryAgent(?string $defaultRepo = 'Quorum-app'): Agent
    {
        [$agent] = $this->chatAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());

        if ($defaultRepo !== null) {
            $agent->set(CodingChatSettingEnum::DEFAULT_REPO->value, $defaultRepo);
        }

        Cache::put('coding-chat-checkouts:' . $agent->getId(), ['Quorum-app', 'SecondLook-app'], 300);

        return $agent;
    }

    /**
     * A task on a plan another agent owns, the way an orchestrator hands one to a worker.
     */
    private function factoryTask(Agent $worker, string $title, bool $inProject = false): Task
    {
        $orchestrator = $this->makeAgent();
        $plan = $this->makePlan(['title' => 'Second Look build'], $orchestrator);

        if ($inProject) {
            $plan->project_id = new CreateProjectAction(ProjectData::from(
                app(Apps::class),
                $this->user(),
                $this->user()->getCurrentCompany(),
                ['title' => 'Second Look', 'agent_id' => $orchestrator->getId()],
            ))->execute()->getId();
            $plan->saveOrFail();
        }

        $task = $this->makeTask($plan);
        $task->title = $title;
        $task->agent_id = $worker->getId();
        $task->saveOrFail();

        return $task;
    }

    /**
     * The session a second conversation opened for Quorum-app, which the factory then refused.
     */
    private function refusedChat(Agent $agent, int $userId): AgentTaskSession
    {
        return $this->liveChat(
            agent: $agent,
            chat: $this->chatSession($agent),
            status: HarnessStatusEnum::FAILED,
            userId: $userId,
        );
    }

    private function startFactoryRun(Task $task): AgentTaskSession
    {
        new RunTaskWorkerJob($task)->handle();

        $run = $this->factoryRunOf($task);
        $run->status = HarnessStatusEnum::RUNNING->value;
        $run->endpoint = 'http://127.0.0.1:4097';
        $run->external_session_id = 'ses_run';
        $run->saveOrFail();

        return $run;
    }

    private function factoryRunOf(Task $task): AgentTaskSession
    {
        $child = Task::query()->where('parent_task_id', $task->getId())->latest('id')->firstOrFail();

        return AgentTaskSession::query()->where('task_id', $child->getId())->firstOrFail();
    }

    private function finishTurn(
        AgentTaskSession $run,
        HarnessStatusEnum $status,
        ?string $reply,
        ?string $error = null
    ): void {
        new DeliverCodingChatTurnAction($run, new HarnessTick(
            status: $status,
            usage: new HarnessUsage(),
            error: $error,
            closedTurns: 1,
            turnReply: $reply,
        ))->execute();
    }

    private function chatSession(Agent $agent): Session
    {
        return Session::create([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'agents_id' => $agent->getId(),
            'uuid' => fake()->uuid(),
            'entity_namespace' => Users::class,
            'entity_id' => $this->user()->getId(),
            'user' => [],
            'content' => [],
        ]);
    }

    private function liveChat(
        Agent $agent,
        Session $chat,
        HarnessStatusEnum $status,
        ?Carbon $heartbeat = null,
        ?int $userId = null
    ): AgentTaskSession {
        return $this->createTaskSession($status, [
            'users_id' => $userId,
            'agent_id' => $agent->getId(),
            'companies_id' => $agent->companies_id,
            'chat_session_id' => $chat->getId(),
            'repo_slug' => 'Quorum-app',
            'branch' => 'opencode/20261110-1200',
            'external_session_id' => 'ses_chat',
            'endpoint' => 'http://127.0.0.1:4097',
            'port' => 4097,
            'session_data_path' => '/Users/joseceron/Documents/Projects/Quorum-app',
            'heartbeat_at' => $heartbeat ?? Carbon::now(),
        ]);
    }

    private function machine(): AgentMachine
    {
        $machine = new AgentMachine();
        $machine->apps_id = app(Apps::class)->getId();
        $machine->companies_id = $this->user()->getCurrentCompany()->getId();
        $machine->name = 'kaneda ' . uniqid();
        $machine->host = '127.0.0.1';
        $machine->ssh_port = 22;
        $machine->ssh_user = 'joseceron';
        $machine->ssh_private_key = 'test-key';
        $machine->runtime = 'host';
        $machine->port_range_start = 20000;
        $machine->port_range_end = 20100;
        $machine->max_agents = 4;
        $machine->is_active = true;
        $machine->saveOrFail();

        return $machine;
    }

    private function say(
        Agent $agent,
        Session $chat,
        string $message,
        ?HarnessTransport $transport = null,
        ?CodingChatCheckouts $checkouts = null
    ): string {
        return new RunCodingChatAction(
            agent: $agent,
            session: $chat,
            message: $message,
            user: $this->user(),
            transport: $transport,
            checkouts: $checkouts ?? $this->checkouts($agent, []),
        )->execute();
    }

    /**
     * @param list<string> $repos what the factory machine would list
     */
    private function checkouts(Agent $agent, array $repos): CodingChatCheckouts
    {
        return new class ($agent, $repos) extends CodingChatCheckouts {
            /**
             * @param list<string> $repos
             */
            public function __construct(
                Agent $agent,
                private readonly array $repos,
            ) {
                parent::__construct($agent);
            }

            #[Override]
            protected function fetch(): array
            {
                return $this->repos;
            }
        };
    }

    private function user(): Users
    {
        return static::$cachedUser;
    }

    /**
     * @param array<string, array<string, mixed>> $payloads path suffix => response
     */
    private function transport(array $payloads = []): HarnessTransport
    {
        $payloads += [
            'message' => ['data' => [['type' => 'assistant', 'time' => ['created' => 1000], 'content' => []]]],
            'permission' => ['data' => []],
            'form' => ['data' => []],
        ];

        return new class ($payloads) implements HarnessTransport {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>|null}> */
            public array $requests = [];

            /**
             * @param array<string, array<string, mixed>> $payloads
             */
            public function __construct(
                private readonly array $payloads,
            ) {
            }

            #[Override]
            public function request(string $method, string $path, ?array $body = null, int $timeout = 30): array
            {
                $this->requests[] = [$method, $path, $body];
                $route = basename((string) parse_url($path, PHP_URL_PATH));

                return $this->payloads[$route] ?? [];
            }
        };
    }

    private function factory(string $output): FactoryServeClient
    {
        $machine = new AgentMachine();
        $machine->name = 'kaneda';

        return new class ($machine, CodingChatSettingEnum::DEFAULT_FACTORY_DIR, $output) extends FactoryServeClient {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(
                AgentMachine $machine,
                string $factoryDir,
                private readonly string $output,
            ) {
                parent::__construct($machine, $factoryDir);
            }

            #[Override]
            protected function run(string $command, int $timeout): string
            {
                $this->commands[] = $command;

                return $this->output;
            }
        };
    }
}
