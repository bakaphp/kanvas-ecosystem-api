<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\Services\FactoryServeClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\DeliverCodingChatTurnAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\OpenCodingChatAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatMessageAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\PostCodingChatProgressAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\ReportFactoryProblemsAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryHealth;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessUsage;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingChatSettingEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\LaunchStageEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\CheckoutInUseException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\HarnessTransportException;
use Kanvas\Intelligence\AgentRuntime\Harness\Exceptions\RuntimeNotListeningException;
use Kanvas\Intelligence\AgentRuntime\Harness\HarnessFactory;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\PollHarnessSessionJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\ReleaseFactoryCheckoutJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\WatchCodingChatJob;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CheckoutHolderNotice;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\CodingChatCheckouts;
use Kanvas\Intelligence\AgentRuntime\Harness\Services\HeldChatMessages;
use Kanvas\Intelligence\AgentRuntime\Harness\Support\TaskDirectives;
use Kanvas\Intelligence\Agents\Actions\Chat\AgentChatKernel;
use Kanvas\Intelligence\Agents\Actions\Chat\RunCodingChatAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AskRepositoryTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemPlanTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\AssignNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateNervousSystemTaskStatusTool;
use Kanvas\Intelligence\Services\KanvasConversationStore;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\RunTaskWorkerJob;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForTaskJob;
use Kanvas\Users\Models\Users;
use Override;
use ReflectionMethod;
use RuntimeException;
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
        $builder = $this->factoryAgent();
        $task = $this->factoryTask($builder, 'Build the login screen in SecondLook-app');

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

    public function testASecondWorkerForTheSameTaskWaitsOnTheRunInsteadOfStartingAnother(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen in SecondLook-app');
        $assigned = $task->status;

        new RunTaskWorkerJob($task)->handle();
        // A second assign queued its own worker before the first had created the run.
        $task->status = $assigned;
        $task->saveOrFail();
        new RunTaskWorkerJob($task->refresh())->handle();

        $this->assertSame(1, Task::query()->where('parent_task_id', $task->getId())->count());
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->refresh()->status);
    }

    public function testALaunchWhoseChatClosedWhileTheCheckoutOpenedReleasesItAndStaysClosed(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::STARTING);
        $factory = new class ($live) extends FactoryServeClient {
            /** @var list<string> */
            public array $commands = [];

            public function __construct(
                private readonly AgentTaskSession $chat,
            ) {
                parent::__construct(new AgentMachine(), CodingChatSettingEnum::DEFAULT_FACTORY_DIR);
            }

            #[Override]
            protected function run(string $command, int $timeout): string
            {
                $this->commands[] = $command;

                if (! str_contains($command, "'open'")) {
                    return '{"released":"true"}';
                }

                // `/end` lands while the baseline runs.
                AgentTaskSession::query()->whereKey($this->chat->getId())->update(['status' => HarnessStatusEnum::COMPLETED->value]);

                return '{"repo":"Quorum-app","directory":"/x","branch":"b","port":"4097","password":"p","provider":"p","model":"m"}';
            }
        };
        $this->app->bind(FactoryServeClient::class, static fn (): FactoryServeClient => $factory);

        new LaunchCodingChatJob(app(Apps::class), $live->getId(), 'fix the login spinner')->handle();

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $live->refresh()->status, 'the closed chat stays closed');
        $this->assertStringContainsString("'release'", $factory->commands[1]);
        Queue::assertNotPushed(PollHarnessSessionJob::class);
    }

    public function testTheFactoryRunsAnswerBecomesTheResultOfTheTaskThatStartedIt(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $task = $this->factoryTask($builder, 'Build the login screen');
        $run = $this->startFactoryRun($task);
        $answer = 'Opened https://github.com/mctekk/Quorum-app/pull/12 against develop; tsc and lint pass.';

        $this->finishTurn($run, HarnessStatusEnum::IDLE, $answer . "\n\n" . AgentTaskSession::RUN_FINISHED_MARKER);

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $run->refresh()->status, 'a task run closes with its turn');
        $this->assertSame(TaskStatusEnum::DONE->value, $run->task()->firstOrFail()->status);

        new RunTaskWorkerJob($task->refresh(), 'Task #1 is now done.')->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::DONE->value, $task->status);
        $this->assertSame($answer, $task->result['worker_summary']);
        $this->assertSame(
            1,
            AgentTaskSession::query()->where('agent_id', $builder->getId())->count(),
            'resuming the task must not start the same work in the factory again'
        );
        Queue::assertPushed(WakeAgentForPlanJob::class);
    }

    /** A turn that ends mid-work ("Now restart both apps") is not a finished ticket, so the run is told to carry on. */
    public function testAFactoryRunThatStopsWithoutSayingItIsDoneIsToldToCarryOn(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);
        $transport = $this->transport();

        $this->finishTurn(
            run: $run,
            status: HarnessStatusEnum::IDLE,
            reply: 'Now restart both apps so they pick up the harness.',
            transport: $transport,
            attempt: 37,
        );

        $run->refresh();
        $this->assertSame(HarnessStatusEnum::RUNNING->value, $run->status, 'the run is still working');
        $this->assertSame(1, (int) $run->turn_offset);
        $this->assertNotSame(TaskStatusEnum::DONE->value, $run->task()->firstOrFail()->status);
        $carryOn = collect($transport->requests)->first(fn (array $request): bool => str_ends_with($request[1], '/prompt'));
        $this->assertNotNull($carryOn, 'the same session is asked to carry on');
        $this->assertStringContainsString(AgentTaskSession::RUN_FINISHED_MARKER, (string) ($carryOn[2]['text'] ?? ''));
        // The nudge shares the run's time limit: polling starts over at attempt 1 and the limit never comes.
        Queue::assertPushed(PollHarnessSessionJob::class, fn (PollHarnessSessionJob $job): bool => $job->attempt === 37);
    }

    /** Said the way a worker's reply is read everywhere else, "Status: blocked" is an answer, not a pause. */
    public function testAFactoryRunThatReportsItselfBlockedInAnyHeadlineFormIsNotNudged(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);

        $this->finishTurn($run, HarnessStatusEnum::IDLE, '**Status: blocked** - the simulator will not boot.');

        $this->assertSame(HarnessStatusEnum::COMPLETED->value, $run->refresh()->status);
        Queue::assertNotPushed(PollHarnessSessionJob::class);
    }

    public function testAFactoryRunThatKeepsStoppingClosesAsFailedNeverAsDone(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);

        $this->finishTurn(
            run: $run,
            status: HarnessStatusEnum::IDLE,
            reply: 'Next I will run the QA.',
            closedTurns: DeliverCodingChatTurnAction::MAX_TASK_RUN_TURNS,
        );
        new RunTaskWorkerJob($task->refresh(), 'Task #1 is now blocked.')->handle();

        $this->assertSame(HarnessStatusEnum::FAILED->value, $run->refresh()->status);
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->refresh()->status);
        $this->assertStringContainsString('stopped before it finished', (string) $task->blocked_reason);
    }

    /** A release lost to a dropped tunnel left the worktree holding its branch, and the next run on it failed. */
    public function testACheckoutTheMacCouldNotReleaseIsReleasedOnceItAnswers(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);
        $machine = new AgentMachine();
        $machine->name = 'kaneda';
        $unreachable = new class ($machine, CodingChatSettingEnum::DEFAULT_FACTORY_DIR) extends FactoryServeClient {
            #[Override]
            protected function run(string $command, int $timeout): string
            {
                throw new HarnessTransportException('Cannot reach machine kaneda (factory)');
            }
        };
        $this->app->bind(FactoryServeClient::class, static fn (): FactoryServeClient => $unreachable);

        $this->finishTurn($run, HarnessStatusEnum::IDLE, 'Pushed and landed.' . "\n" . AgentTaskSession::RUN_FINISHED_MARKER);

        Queue::assertPushed(ReleaseFactoryCheckoutJob::class, fn (ReleaseFactoryCheckoutJob $job): bool => $job->sessionId === $run->getId());

        $factory = $this->bindFactory('{"released":"true"}');
        new ReleaseFactoryCheckoutJob(app(Apps::class), $run->getId())->handle();

        $this->assertStringContainsString("'release'", $factory->commands[0] ?? '');
    }

    /** A checkout someone else holds refuses every retry; queueing one only fills the failed jobs. */
    public function testACheckoutHeldBySomeoneElseIsNotReleasedAgainAndAgain(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        $run = $this->startFactoryRun($task);
        $refusal = json_encode(['error' => 'example-app is in use by holder-uuid', 'holder' => 'holder-uuid']);
        $this->bindFactory($refusal);

        $this->finishTurn($run, HarnessStatusEnum::IDLE, 'Pushed and landed.' . "\n" . AgentTaskSession::RUN_FINISHED_MARKER);

        Queue::assertNotPushed(ReleaseFactoryCheckoutJob::class);
        new ReleaseFactoryCheckoutJob(app(Apps::class), $run->getId())->handle();
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

    public function testATaskRunWorksOnItsOwnBranchUnderItsAgentNamedForItsTicket(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $worker = $this->factoryAgent();
        $worker->name = 'Akira Builder';
        $worker->saveQuietly();
        $task = $this->factoryTask($worker, 'Build the login screen');
        $run = $this->startFactoryRun($task);

        $this->assertSame('akira-builder/task-' . $task->getId() . '-build-the-login-screen', $run->taskRunBranch());
        $this->assertNull(
            $this->liveChat($agent, $chat, HarnessStatusEnum::IDLE)->taskRunBranch(),
            'a chat works in the checkout as it is'
        );
    }

    public function testTheFactoryClientAsksForAWorktreeForATaskRun(): void
    {
        $client = $this->factory(json_encode([
            'directory' => '/Users/joseceron/Documents/Projects/opencode-factory/worktrees/Quorum-app-owner-1',
            'branch' => 'akira/task-7-build-the-login-screen',
            'port' => '4097',
            'password' => 'pw',
            'provider' => 'kanvas-openrouter',
            'model' => 'deepseek/deepseek-v4.1-flash-1017',
        ]));

        $client->open(
            repo: 'Quorum-app',
            owner: 'owner-1',
            worktreeBranch: 'akira/task-7-build-the-login-screen',
        );

        $this->assertStringContainsString("'--worktree' 'akira/task-7-build-the-login-screen'", $client->commands[0]);
    }

    public function testATaskRunWaitsItsTurnWhileAnotherRunsOnTheMacWithoutFailingItsTask(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $this->startFactoryRun($this->factoryTask($builder, 'Build the login screen'));
        $second = $this->factoryTask($builder, 'Build the settings screen');
        new RunTaskWorkerJob($second)->handle();
        $waiting = $this->factoryRunOf($second);

        new LaunchCodingChatJob(app(Apps::class), $waiting->getId(), 'the brief')->handle();

        $waiting->refresh();
        $this->assertSame(HarnessStatusEnum::STARTING->value, $waiting->status);
        $this->assertSame(LaunchStageEnum::WAITING_FOR_FACTORY, $waiting->launchStage());
        $this->assertSame(TaskStatusEnum::WAITING->value, $second->refresh()->status, 'waiting its turn is not a failure');
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => $job->sessionId === $waiting->getId() && $job->delay !== null
        );
    }

    public function testATaskRunGoesStraightToTheMacWhenNothingElseRunsThere(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Build the login screen');
        new RunTaskWorkerJob($task)->handle();
        $run = $this->factoryRunOf($task);

        new LaunchCodingChatJob(app(Apps::class), $run->getId(), 'the brief')->handle();

        $run->refresh();
        $this->assertNotSame(LaunchStageEnum::WAITING_FOR_FACTORY, $run->launchStage());
        $this->assertStringContainsString(
            'Could not start',
            (string) $run->error_message,
            'it went to the Mac, which a test cannot reach'
        );
    }

    public function testATaskWithNoCheckoutToWorkInIsBlockedWithWhatToDo(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent(defaultRepo: null);
        $task = $this->factoryTask($builder, 'Fix the spinner');

        new RunTaskWorkerJob($task)->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('CODING_CHAT_DEFAULT_REPO', (string) $task->blocked_reason);
        Queue::assertNotPushed(LaunchCodingChatJob::class);
    }

    public function testATaskThatDeclaresItsRepoWorksThereWhateverElseItNames(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent(defaultRepo: null);
        $task = $this->factoryTask($builder, 'Kickoff: create SecondLook-app from the template');
        $task->description = "Repo: Quorum-app\nRun the new-app tool for SecondLook-app.";
        $task->saveOrFail();

        new RunTaskWorkerJob($task)->handle();

        $this->assertSame('Quorum-app', $this->factoryRunOf($task)->repo_slug, 'two checkouts named, one declared');
    }

    public function testATaskDeclaringACheckoutCreatedSinceTheListWasCachedFindsIt(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $this->bindFactory('{"repos":"Quorum-app,SecondLook-app,PlantPal-app"}');
        $task = $this->factoryTask($builder, 'Set the identity (Repo: plantpal-app)');

        new RunTaskWorkerJob($task)->handle();

        $this->assertSame('PlantPal-app', $this->factoryRunOf($task)->repo_slug, 'the kickoff before it made the checkout');
    }

    public function testATaskDeclaringACheckoutTheMacDoesNotHaveIsBlockedInsteadOfUsingTheDefault(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $this->bindFactory('{"repos":"Quorum-app,SecondLook-app"}');
        $task = $this->factoryTask($builder, 'Build the home screen. Repo: Ghost-app');

        new RunTaskWorkerJob($task)->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('Ghost-app', (string) $task->blocked_reason);
        $this->assertStringContainsString('kickoff', (string) $task->blocked_reason);
        Queue::assertNotPushed(LaunchCodingChatJob::class);
    }

    public function testTheDeclaredRepoIsReadAsAFolderNameHoweverItIsWritten(): void
    {
        $this->assertSame('Quorum-app', TaskDirectives::repo('Repo: Quorum-app.'));
        $this->assertSame('Quorum-app', TaskDirectives::repo('**Repo:** `mctekk/Quorum-app`'));
        $this->assertSame('Quorum-app', TaskDirectives::repo('Repository: https://github.com/mctekk/Quorum-app.git'));
        $this->assertSame('Quorum-app', TaskDirectives::repo("Repo: Quorum-app\nDone when: repo: quorum-app builds"));
        $this->assertNull(TaskDirectives::repo('Open the repository and fix the spinner in Quorum-app'));

        $this->expectException(ValidationException::class);
        TaskDirectives::repo("Repo: Quorum-app\nRepo: SecondLook-app");
    }

    /** A run opens one checkout: "Repo: a and b" opened `a` and silently dropped `b`. */
    public function testARepoLineNamingTwoRepositoriesIsRefusedNotCutToTheFirst(): void
    {
        $this->assertSame('example-app', TaskDirectives::repo('Repo: example-app, branch develop'));
        $this->assertSame('example-app', TaskDirectives::repo('Repo: example-app (the template)'));

        foreach (['Repo: example-app and kanvas-ecosystem-api', 'Repo: example-app, mctekk/other-app', '**Repo:** `example-app` & kanvas-ecosystem-api'] as $line) {
            try {
                TaskDirectives::repo($line);
                $this->fail('Two repositories were read as one: ' . $line);
            } catch (ValidationException $e) {
                $this->assertStringContainsString('one task per repository', $e->getMessage());
            }
        }
    }

    public function testABranchIsReadOnlyFromALineOfItsOwn(): void
    {
        $this->assertSame('ticket/12-login', TaskDirectives::branch("Repo: Quorum-app\nBranch: ticket/12-login."));
        $this->assertSame('ticket/3-home', TaskDirectives::branch('- **Branch:** `ticket/3-home`'));
        $this->assertNull(TaskDirectives::branch('Open the pull request into the base branch: develop'));

        $this->expectException(ValidationException::class);
        TaskDirectives::branch('Branch: develop');
    }

    public function testTheTasksOfATicketShareTheBranchTheyName(): void
    {
        Queue::fake();
        $designer = $this->factoryAgent();
        $builder = $this->factoryAgent();
        $design = $this->factoryTask($designer, 'Design the login screen');
        $logic = $this->factoryTask($builder, 'Wire the login screen');

        foreach ([$design, $logic] as $task) {
            $task->description = "Repo: Quorum-app\nBranch: ticket/12-login-screen";
            $task->saveOrFail();
            new RunTaskWorkerJob($task)->handle();
        }

        $this->assertSame('ticket/12-login-screen', $this->factoryRunOf($design)->taskRunBranch());
        $this->assertSame('ticket/12-login-screen', $this->factoryRunOf($logic)->taskRunBranch(), 'logic lands on the design');
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => str_contains($job->instruction, 'build on the commits already there')
        );
    }

    /**
     * A backend check came back as a 305-line markdown file force-added past .gitignore, a draft pull
     * request on the API and half an hour of re-running CI: the brief said "open a draft pull request
     * unless the task says otherwise", and an audit said nothing.
     */
    public function testACheckIsAnsweredInTheReplyNotAPullRequest(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Verify backend support for the records organizer');

        new RunTaskWorkerJob($task)->handle();

        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => str_contains($job->instruction, 'open no pull request')
                && str_contains($job->instruction, 'A report is never a file in the repository')
        );
    }

    /** Ticket 1's design step, told "commit only", pushed and opened the pull request the ship step owns. */
    public function testAStepOnASharedTicketBranchPushesOnlyWhenItsTaskSaysTo(): void
    {
        Queue::fake();
        $design = $this->factoryTask($this->factoryAgent(), 'Design the login screen');
        $design->description = "Repo: Quorum-app\nBranch: ticket/12-login-screen\nCommit only.";
        $design->saveOrFail();
        $own = $this->factoryTask($this->factoryAgent(), 'Fix the login copy');

        new RunTaskWorkerJob($design)->handle();
        new RunTaskWorkerJob($own)->handle();

        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => str_contains($job->instruction, 'Push the branch and open a pull request only if this task tells you to')
                && ! str_contains($job->instruction, 'Finish the task end to end')
        );
        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => str_contains($job->instruction, 'Finish the task end to end')
        );
    }

    public function testATaskNamingAnIntegrationBranchIsBlockedBeforeAnythingOpens(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $task = $this->factoryTask($builder, 'Wire the login screen');
        $task->description = "Repo: Quorum-app\nBranch: develop";
        $task->saveOrFail();

        new RunTaskWorkerJob($task)->handle();

        $task->refresh();
        $this->assertSame(TaskStatusEnum::BLOCKED->value, $task->status);
        $this->assertStringContainsString('integration branch', (string) $task->blocked_reason);
        Queue::assertNotPushed(LaunchCodingChatJob::class);
    }

    public function testATicketsLogicAssignedWhileItsDesignRunsWaitsForIt(): void
    {
        Queue::fake();
        [$design, $logic] = $this->ticketLine();

        new WakeAgentForTaskJob($logic)->handle();

        Queue::assertNotPushed(RunTaskWorkerJob::class);
        $this->assertSame(TaskStatusEnum::PENDING->value, $logic->refresh()->status);
    }

    public function testATicketsLogicStartsOnItsOwnWhenItsDesignIsDone(): void
    {
        Queue::fake();
        [$design, $logic] = $this->ticketLine();
        $run = $this->startFactoryRun($design);
        $this->finishTurn($run, HarnessStatusEnum::IDLE, "Designed the login screen; committed on ticket/12-login-screen.\n" . AgentTaskSession::RUN_FINISHED_MARKER);

        new RunTaskWorkerJob($design->refresh(), 'Task #1 is now done.')->handle();

        $this->assertSame(TaskStatusEnum::DONE->value, $design->refresh()->status);
        Queue::assertPushed(WakeAgentForTaskJob::class, fn (WakeAgentForTaskJob $job): bool => $job->task->is($logic));
    }

    /** Whoever wrote the task, the factory works by the repository's own rules and runs what it ships. */
    public function testEveryFactoryTaskReadsTheRepositoryRulesAndRunsItsWorkBeforeAPullRequest(): void
    {
        Queue::fake();
        $task = $this->factoryTask($this->factoryAgent(), 'Fix the login copy');

        new RunTaskWorkerJob($task)->handle();

        Queue::assertPushed(
            LaunchCodingChatJob::class,
            fn (LaunchCodingChatJob $job): bool => str_contains($job->instruction, 'read the repository\'s `CLAUDE.md` and its `specs/`')
                && str_contains($job->instruction, 'Never open a pull request for work you have not run')
                && str_contains($job->instruction, 'both iOS and Android')
        );
    }

    /** A planner given the factory's answer as its second step started at once, found nothing, and blocked. */
    public function testAKanvasStepWaitsForTheFactoryStepAheadOfIt(): void
    {
        Queue::fake();
        [$check, $planning] = $this->factoryThenKanvasLine();

        new WakeAgentForTaskJob($planning)->handle();

        $this->assertSame(TaskStatusEnum::PENDING->value, $planning->refresh()->status);
        $this->assertTrue(
            Event::query()
                ->where('event_type', 'plan.task.queued')
                ->where('source_entity_id', $planning->getId())
                ->exists(),
            'held for the factory step, not run',
        );
    }

    public function testAKanvasStepStartsWhenTheFactoryStepAheadOfItIsDone(): void
    {
        Queue::fake();
        [$check, $planning] = $this->factoryThenKanvasLine();
        $run = $this->startFactoryRun($check);
        $this->finishTurn($run, HarnessStatusEnum::IDLE, "The template has a list screen and the API serves messages.\n" . AgentTaskSession::RUN_FINISHED_MARKER);

        new RunTaskWorkerJob($check->refresh(), 'Task #1 is now done.')->handle();

        Queue::assertPushed(WakeAgentForTaskJob::class, fn (WakeAgentForTaskJob $job): bool => $job->task->is($planning));
    }

    /** A plan wake to a factory owner does nothing, so a planner's task sent back on its plan must be woken itself. */
    public function testAKanvasStepSentBackOnAFactoryOwnedPlanRunsAgain(): void
    {
        Queue::fake();
        [$check, $planning] = $this->factoryThenKanvasLine();
        $check->plan->update(['agent_id' => $check->agent_id]);
        $user = $this->user();
        $status = new UpdateNervousSystemTaskStatusTool()->withContext(app(Apps::class), $user->getCurrentCompany(), $user);

        $status($planning->getId(), 'blocked', blocked_reason: 'The check is not back yet.');
        $status($planning->getId(), 'pending');

        Queue::assertPushed(WakeAgentForTaskJob::class, fn (WakeAgentForTaskJob $job): bool => $job->task->is($planning));
    }

    /** A plan with no factory work keeps starting its tasks as it always did. */
    public function testAKanvasStepAfterAKanvasStepIsNotHeld(): void
    {
        $first = $this->kanvasWorkerTask();
        $second = $this->makeTask($first->plan, sequence: 2);
        $second->agent_id = $first->agent_id;
        $second->saveOrFail();
        $first->update(['sequence' => 1]);

        $this->assertTrue($second->refresh()->waitsOnEarlierTasks());
        $this->assertFalse($second->waitsOnEarlierFactoryWork());
    }

    public function testABlockedDesignHoldsTheTicketsLogicBack(): void
    {
        Queue::fake();
        [$design, $logic] = $this->ticketLine();
        $run = $this->startFactoryRun($design);
        $this->finishTurn($run, HarnessStatusEnum::IDLE, 'BLOCKED - the design system spec is missing.');

        new RunTaskWorkerJob($design->refresh(), 'Task #1 is now done.')->handle();

        $this->assertSame(TaskStatusEnum::BLOCKED->value, $design->refresh()->status);
        Queue::assertNotPushed(WakeAgentForTaskJob::class);
        $this->assertTrue($logic->refresh()->waitsOnEarlierTasks());
    }

    public function testATaskWhoseRunIsUnderWayCannotBeHandedToAnotherAgent(): void
    {
        Queue::fake();
        [$design] = $this->ticketLine();
        $this->startFactoryRun($design);
        $designer = $design->agent;
        $other = $this->factoryAgent();

        $result = new AssignNervousSystemTaskTool()->withContext(
            app(Apps::class),
            $this->user()->getCurrentCompany(),
            $this->user(),
        )($design->getId(), $other->getId());

        $this->assertStringContainsString('NOT reassigned', (string) ($result['error'] ?? ''));
        $this->assertTrue($design->refresh()->agent?->is($designer), 'the board still names who is doing the work');
    }

    /** An orchestrator handed a whole kickoff plan to a factory agent, heard "it will auto-run", and nothing ran. */
    public function testAWholePlanIsNotHandedToAFactoryAgent(): void
    {
        Queue::fake();
        $plan = $this->makePlan(['title' => 'Example App kickoff']);
        $owner = $plan->agent_id;

        $result = new AssignNervousSystemPlanTool()->withContext(
            app(Apps::class),
            $this->user()->getCurrentCompany(),
            $this->user(),
        )($plan->getId(), $this->factoryAgent()->getId());

        $this->assertStringContainsString('NOT assigned', (string) ($result['error'] ?? ''));
        $this->assertStringContainsString('assign_nervous_system_task', (string) $result['error']);
        $this->assertSame($owner, $plan->refresh()->agent_id);
    }

    /** A "create the app" task with no Repo line runs in the agent's default checkout; the orchestrator is told so. */
    public function testAssigningAFactoryTaskWithoutARepoLineSaysWhereItRuns(): void
    {
        Queue::fake();
        $task = $this->makeTask($this->makePlan(['title' => 'Kickoff']));
        $task->description = 'Create repository example-app from the mobile template.';
        $task->saveOrFail();
        $assign = new AssignNervousSystemTaskTool()->withContext(
            app(Apps::class),
            $this->user()->getCurrentCompany(),
            $this->user(),
        );

        $builder = $this->factoryAgent('factory-sandbox');
        $builder->type->update(['handler' => SystemUserAgent::class]);

        $result = $assign($task->getId(), $builder->getId());

        $this->assertStringContainsString('factory-sandbox', (string) ($result['note'] ?? ''));

        $task->description = "Repo: Quorum-app\nNew app: example-app";
        $task->saveOrFail();

        $this->assertArrayNotHasKey('note', $assign($task->getId(), $builder->getId()));
    }

    public function testAnOrchestratorsTaskThatNamesARepoRunsInTheFactoryUnderItsName(): void
    {
        Queue::fake();
        $orchestrator = $this->orchestratorWithFactoryTasks();
        $task = $this->factoryTask($orchestrator, 'Kickoff 1: create the app repository', inProject: true);
        $task->description = "Repo: Quorum-app\nNew app: PlantPal-app";
        $task->saveOrFail();

        new WakeAgentForTaskJob($task)->handle();
        Queue::assertPushed(RunTaskWorkerJob::class, fn (RunTaskWorkerJob $job): bool => $job->task->is($task));

        new RunTaskWorkerJob($task)->handle();

        $run = $this->factoryRunOf($task);
        $this->assertSame($orchestrator->getId(), $run->agent_id, 'on the Mac as the orchestrator, not as a worker');
        $this->assertSame('Quorum-app', $run->repo_slug);
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->refresh()->status);
    }

    public function testAnOrchestratorKeepsItsOwnModelForEverythingElse(): void
    {
        $orchestrator = $this->orchestratorWithFactoryTasks();

        $this->assertFalse(CodingChatSettingEnum::isEnabledFor($orchestrator), 'its chat is not sent to the Mac');
        $this->assertFalse(CodingChatSettingEnum::runsTaskInFactory($orchestrator, "Review the plan\nCheck the brief against Stage 1"));
        $this->assertTrue(CodingChatSettingEnum::runsTaskInFactory($orchestrator, "Ship ticket 12\nRepo: Quorum-app"));
        $this->assertTrue(
            CodingChatSettingEnum::runsTaskInFactory($orchestrator, "Repo: Quorum-app\nRepo: Junkmaxy-app"),
            'a contradiction still reaches the worker, which blocks it with the reason'
        );
    }

    public function testAssigningATaskToAFactoryAgentHandsItToTheWorkerOnce(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $task = $this->factoryTask($builder, 'Build the login screen', inProject: true);

        new WakeAgentForTaskJob($task)->handle();

        Queue::assertPushed(RunTaskWorkerJob::class, fn (RunTaskWorkerJob $job): bool => $job->task->is($task));

        Queue::fake();
        $this->startFactoryRun($task);
        new WakeAgentForTaskJob($task->refresh())->handle();

        Queue::assertNotPushed(RunTaskWorkerJob::class);
    }

    public function testAPlanWakeForAFactoryAgentOpensNoChatAndTellsTheAgentThatAskedForThePlan(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $plan = $this->planAFactoryAgentOwns($builder);
        $orchestrator = $plan->createdByAgent;

        new WakeAgentForPlanJob(
            $plan,
            WakeAgentForPlanJob::REASON_TASK_COMPLETED,
            'Task #1 ("Build the login screen") is now done.'
        )->handle();

        $this->assertFalse(
            AgentTaskSession::query()->where('agent_id', $builder->getId())->exists(),
            'the wake was not typed into a coding chat'
        );
        Queue::assertNotPushed(LaunchCodingChatJob::class);
        Queue::assertPushed(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->wakeAgent?->is($orchestrator) === true
                && $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
                && str_contains((string) $job->triggerMessage, 'is now done')
        );
    }

    public function testAPlanWakeForAFactoryAgentWithNothingToPassOnWakesNobody(): void
    {
        Queue::fake();
        $builder = $this->factoryAgent();
        $plan = $this->planAFactoryAgentOwns($builder);

        new WakeAgentForPlanJob($plan, WakeAgentForPlanJob::REASON_PLAN_ASSIGNED)->handle();

        $this->assertFalse(AgentTaskSession::query()->where('agent_id', $builder->getId())->exists());
        Queue::assertNotPushed(LaunchCodingChatJob::class);
        Queue::assertNotPushed(WakeAgentForProjectJob::class);
    }

    /**
     * A planner in Kanvas asked to audit the mobile template blocked with "no repository read tool". It
     * asks the factory instead: a read-only sub-task its own task waits on.
     */
    public function testAnAgentInKanvasAsksTheFactoryAboutARepository(): void
    {
        Queue::fake();
        $reader = $this->factoryAgent(defaultRepo: 'SecondLook-app');
        $task = $this->kanvasWorkerTask();

        $result = $this->askRepository()(
            task_id: $task->getId(),
            repository: 'SecondLook-app',
            question: 'Which screens and shared components exist?',
        );

        $this->assertTrue($result['success'], json_encode($result));

        /** @var Task $question */
        $question = $task->children()->firstOrFail();
        $this->assertSame($reader->getId(), (int) $question->agent_id);
        $this->assertSame('SecondLook-app', TaskDirectives::repo($question->fullText()));
        $this->assertStringContainsString('Read-only', (string) $question->description);
        $this->assertTrue(CodingChatSettingEnum::runsTaskInFactory($reader, $question->fullText()));
        Queue::assertPushed(
            WakeAgentForTaskJob::class,
            fn (WakeAgentForTaskJob $job): bool => $job->task->getId() === $question->getId()
        );
    }

    public function testAskingTheSameQuestionTwiceAsksTheFactoryOnce(): void
    {
        Queue::fake();
        $this->factoryAgent(defaultRepo: 'SecondLook-app');
        $task = $this->kanvasWorkerTask();
        $ask = $this->askRepository();

        $ask(task_id: $task->getId(), repository: 'SecondLook-app', question: 'What does auth use?');
        $ask(task_id: $task->getId(), repository: 'SecondLook-app', question: 'What does auth use?');

        $this->assertSame(1, $task->children()->count());
    }

    /** An API answer cut at the cap read as complete, and the asker blocked for detail it already had. */
    public function testALongFactoryAnswerHandedBackSaysWhereTheRestIs(): void
    {
        Bus::fake();
        $this->factoryAgent(defaultRepo: 'SecondLook-app');
        $task = $this->kanvasWorkerTask();
        $asked = $this->askRepository()(task_id: $task->getId(), repository: 'SecondLook-app', question: 'Which operations serve records?');
        $question = Task::query()->findOrFail($asked['question_task_id']);
        $task->update(['status' => TaskStatusEnum::WAITING->value]);

        new UpdateTaskStatusAction(
            task: $question,
            newStatus: TaskStatusEnum::DONE,
            result: ['worker_summary' => str_repeat('The messages query takes a type filter. ', 150)],
        )->execute();

        $summary = (string) TaskChildOutcome::query()->where('child_task_id', $question->getId())->sole()->summary;
        $this->assertStringContainsString('get_nervous_system_task', $summary);
        $this->assertStringContainsString('#' . $question->getId(), $summary);
    }

    /** A later turn rewords its question; the repository it asks is what makes it the same question. */
    public function testARewordedQuestionToARepositoryAlreadyAskedWaitsForTheFirstAnswer(): void
    {
        Queue::fake();
        $this->factoryAgent(defaultRepo: 'SecondLook-app');
        $task = $this->kanvasWorkerTask();
        $ask = $this->askRepository();

        $first = $ask(task_id: $task->getId(), repository: 'SecondLook-app', question: 'What does auth use?');
        $again = $ask(task_id: $task->getId(), repository: 'SecondLook-app', question: 'Which login screens and services exist?');

        $this->assertSame(1, $task->children()->count());
        $this->assertSame($first['question_task_id'], $again['question_task_id']);
    }

    public function testATaskThatDeclaresARepoIsNotAssignedToAnAgentInKanvas(): void
    {
        Queue::fake();
        $reader = $this->factoryAgent(defaultRepo: 'SecondLook-app');
        $reader->type->update(['handler' => SystemUserAgent::class]);
        $task = $this->kanvasWorkerTask("Repo: SecondLook-app\n\nAudit the template.");
        $planner = $this->kanvasAgent();
        $assign = new AssignNervousSystemTaskTool()->withContext(
            app(Apps::class),
            $this->user()->getCurrentCompany(),
            $this->user(),
        );

        $refused = $assign(task_id: $task->getId(), agent_id: $planner->getId());

        $this->assertStringContainsString('NOT assigned', (string) ($refused['error'] ?? ''));
        $this->assertStringContainsString((string) $reader->name, (string) $refused['error']);
        $this->assertArrayNotHasKey('error', $assign(task_id: $task->getId(), agent_id: $reader->getId()));
    }

    public function testAnAgentInKanvasIsOfferedTheQuestionAndAFactoryAgentIsNot(): void
    {
        $reader = $this->factoryAgent(defaultRepo: 'SecondLook-app');

        $this->assertContains('ask_repository', $this->toolNamesOf($this->kanvasAgent()));
        $this->assertNotContains('ask_repository', $this->toolNamesOf($reader));
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

    public function testTheHolderNoticeWorksOnTheQueueWhereNobodyIsLoggedIn(): void
    {
        [$agent, $first] = $this->chatAgent();
        $holder = $this->liveChat(
            agent: $agent,
            chat: $first,
            status: HarnessStatusEnum::IDLE,
            userId: $this->user()->getId(),
        );
        $refused = $this->refusedChat($agent, $this->user()->getId());
        app('auth')->forgetGuards();

        $message = new CheckoutHolderNotice($refused, $holder->uuid)->message();

        $this->assertStringContainsString('already open in another conversation of yours', $message);
    }

    public function testAMessageSentWhileTheCheckoutOpensIsHeldForTheAgentNotRefused(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::STARTING);

        $reply = $this->say($agent, $chat, 'you remember how to run it, right?');

        $this->assertStringContainsString('I will pass this to the agent as soon as it starts', $reply);
        $this->assertSame(['you remember how to run it, right?'], HeldChatMessages::take($live));
    }

    public function testALaunchThatCrashesClosesItsSessionInsteadOfLeavingItOpening(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::STARTING);

        new LaunchCodingChatJob(app(Apps::class), $live->getId(), '')->failed(new RuntimeException('boom'));

        $live->refresh();
        $this->assertSame(HarnessStatusEnum::FAILED->value, $live->status);
        $this->assertStringContainsString('boom', (string) $live->error_message);
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
        $builder = $this->factoryAgent();
        $run = $this->startFactoryRun($this->factoryTask($builder, 'Build the login screen'));

        $message = new CheckoutHolderNotice($this->refusedChat($builder, $this->user()->getId()), $run->uuid)->message();

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

    public function testALongTurnSaysEveryMinuteThatItIsStillWorkingAndOnWhat(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);
        $tick = new HarnessTick(
            status: HarnessStatusEnum::RUNNING,
            usage: new HarnessUsage(),
            narration: ['Metro is up, building the app now.'],
            activity: 'running `.kanvas/device android`',
            turnStartedAt: Carbon::now()->subMinutes(3)->getTimestamp(),
        );

        $update = new PostCodingChatProgressAction($live, $tick)->execute();

        $this->assertSame(
            "⏳ Still working · 3 min · running `.kanvas/device android`\n\n> Metro is up, building the app now.",
            $update
        );
        $this->assertNull(
            new PostCodingChatProgressAction($live, $tick)->execute(),
            'a second poller in the same minute stays quiet'
        );
    }

    public function testEveryMinuteOfALongTurnIsSaidOnceWithoutSkippingOne(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);
        $startedAt = Carbon::now()->subMinutes(10)->startOfSecond();
        $tick = new HarnessTick(
            status: HarnessStatusEnum::RUNNING,
            usage: new HarnessUsage(),
            turnStartedAt: $startedAt->getTimestamp(),
        );
        $minutes = [];

        try {
            // A poll that drifted to 5:58 into the turn.
            Carbon::setTestNow($startedAt->copy()->addSeconds(5 * 60 + 58));

            for ($poll = 0; $poll < 4; $poll++) {
                $minutes[] = new PostCodingChatProgressAction($live, $tick)->execute();
                Carbon::setTestNow(Carbon::now()->addSeconds(PostCodingChatProgressAction::secondsToNextMinute($live, $tick)));
            }
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(
            ['⏳ Still working · 5 min', '⏳ Still working · 6 min', '⏳ Still working · 7 min', '⏳ Still working · 8 min'],
            $minutes
        );
    }

    public function testATurnInItsFirstMinuteAndAWorkflowTaskRunPostNoProgress(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChat($agent, $chat, HarnessStatusEnum::RUNNING);
        $justStarted = new HarnessTick(
            status: HarnessStatusEnum::RUNNING,
            usage: new HarnessUsage(),
            turnStartedAt: Carbon::now()->subSeconds(10)->getTimestamp(),
        );

        $this->assertNull(new PostCodingChatProgressAction($live, $justStarted)->execute());
        $this->assertNull(
            new PostCodingChatProgressAction($live, new HarnessTick(status: HarnessStatusEnum::RUNNING, usage: new HarnessUsage()))->execute(),
            'a turn the agent has not written in yet has only just begun'
        );

        $run = $this->startFactoryRun($this->factoryTask($this->factoryAgent(), 'Build the login screen'));
        $longRun = new HarnessTick(
            status: HarnessStatusEnum::RUNNING,
            usage: new HarnessUsage(),
            turnStartedAt: Carbon::now()->subMinutes(5)->getTimestamp(),
        );

        $this->assertNull(new PostCodingChatProgressAction($run, $longRun)->execute());
        $this->assertNull(
            PostCodingChatProgressAction::secondsToNextMinute($run, $longRun),
            'a task run keeps the poller\'s own pace'
        );
    }

    public function testAMessageToAStoppedFactoryServerRestartsItAndStillReachesTheAgent(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::IDLE);
        $factory = $this->bindFactory('{"running":"true","port":"4097"}');
        $transport = $this->downOnce($this->transport());

        $reply = $this->say(
            $agent,
            $chat,
            'now add a test',
            $transport
        );

        $this->assertSame('⏳ On it.', $reply);
        $this->assertStringEndsWith("'start' 2>/dev/null", $factory->commands[0]);
        $this->assertSame(HarnessStatusEnum::RUNNING->value, $live->refresh()->status);
        $this->assertSame(
            ['POST', '/api/session/ses_chat/prompt', ['text' => 'now add a test', 'delivery' => 'queue']],
            $transport->requests[0]
        );
    }

    public function testAFactoryServerThatWillNotStartSaysWhyAndLeavesTheChatReadyForTheNextMessage(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::IDLE);
        $this->bindFactory('{"error":"joseceron is not logged in on the Mac\'s screen; log in and retry"}');

        $reply = $this->say(
            $agent,
            $chat,
            'now add a test',
            $this->downOnce($this->transport())
        );

        $this->assertStringContainsString('not logged in on the Mac', $reply);
        $this->assertSame(HarnessStatusEnum::IDLE->value, $live->refresh()->status);
    }

    public function testTheWatcherRestartsAFactoryServerItFindsStoppedBeforeAnyoneWrites(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::IDLE);
        // One line answers both calls: `health` reads the snapshot, `start` reads `running`.
        $factory = $this->bindFactory('{"at":100,"server":false,"metro":false,"running":"true"}');

        new WatchCodingChatJob(app(Apps::class), $live->getId())->handle();

        $this->assertCount(2, $factory->commands);
        $this->assertStringContainsString("'health'", $factory->commands[0]);
        $this->assertStringEndsWith("'start' 2>/dev/null", $factory->commands[1]);
    }

    public function testTheWatcherLeavesARunningOrUnreportedServerAlone(): void
    {
        Queue::fake();
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::IDLE);
        $factory = $this->bindFactory('{"at":100,"metro":false}');

        new WatchCodingChatJob(app(Apps::class), $live->getId())->handle();

        $this->assertCount(1, $factory->commands);
    }

    public function testTheFactoryForASessionIsBuiltFromItsOwnMachineAndAgent(): void
    {
        [$agent, $chat] = $this->chatAgent();
        $live = $this->liveChatOnAMachine($agent, $chat, HarnessStatusEnum::IDLE);

        $this->assertInstanceOf(FactoryServeClient::class, HarnessFactory::codingFactoryFor($live));

        $live->agent_machine_id = null;
        $this->assertNull(HarnessFactory::codingFactoryFor($live->unsetRelation('machine')));
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
     * One ticket's design (designer) and logic (builder), in order on one plan, both assigned up front.
     *
     * @return array{0: Task, 1: Task}
     */
    private function ticketLine(): array
    {
        $designer = $this->factoryAgent();
        $builder = $this->factoryAgent();
        $design = $this->factoryTask($designer, 'Design the login screen', inProject: true);
        $logic = $this->makeTask($design->plan, sequence: 2);

        foreach ([[$design, $designer, 1], [$logic, $builder, 2]] as [$task, $agent, $sequence]) {
            $task->agent_id = $agent->getId();
            $task->sequence = $sequence;
            $task->description = "Repo: Quorum-app\nBranch: ticket/12-login-screen";
            $task->saveOrFail();
        }

        return [$design->refresh(), $logic->refresh()];
    }

    /**
     * A factory check as step 1 and a planner in Kanvas as step 2 of one project plan.
     *
     * @return array{0: Task, 1: Task}
     */
    private function factoryThenKanvasLine(): array
    {
        $check = $this->factoryTask($this->factoryAgent(), 'Check what the template has', inProject: true);
        $check->sequence = 1;
        $check->description = 'Repo: Quorum-app';
        $check->saveOrFail();

        $planner = $this->kanvasAgent();
        $planning = $this->makeTask($check->plan, sequence: 2);
        $planning->agent_id = $planner->getId();
        $planning->saveOrFail();

        return [$check->refresh(), $planning->refresh()];
    }

    /**
     * An orchestrator whose chat stays with its own model and whose `Repo:` tasks run on the Mac.
     */
    private function orchestratorWithFactoryTasks(): Agent
    {
        $agent = $this->makeAgent();
        $agent->set(AgentCustomFieldEnum::MACHINE_ID->value, $this->machine()->getId());
        $agent->set(CodingChatSettingEnum::FACTORY_TASKS->value, true);
        Cache::put('coding-chat-checkouts:' . $agent->getId(), ['Quorum-app', 'SecondLook-app'], 300);

        return $agent;
    }

    /**
     * A project plan an orchestrator created and a factory agent came to own, the way assigning its
     * first task leaves it.
     */
    private function planAFactoryAgentOwns(Agent $worker): Plan
    {
        $plan = $this->factoryTask($worker, 'Build the login screen', inProject: true)->plan;
        $plan->created_by_agent_id = $plan->agent_id;
        $plan->agent_id = $worker->getId();
        $plan->saveOrFail();

        return $plan->refresh();
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
        ?string $error = null,
        int $closedTurns = 1,
        ?HarnessTransport $transport = null,
        int $attempt = 1
    ): void {
        new DeliverCodingChatTurnAction(
            $run,
            new HarnessTick(
                status: $status,
                usage: new HarnessUsage(),
                error: $error,
                closedTurns: $closedTurns,
                turnReply: $reply,
            ),
            $transport,
            $attempt,
        )->execute();
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

    /** An internal agent that runs in Kanvas with its own model, the way the planner does. */
    private function kanvasAgent(): Agent
    {
        $agent = $this->makeAgent();
        $agent->type->update(['handler' => SystemUserAgent::class]);

        return $agent->refresh();
    }

    /** A task an agent in Kanvas is working, the way a planner holds one. */
    private function kanvasWorkerTask(string $description = 'Plan the tickets.'): Task
    {
        $planner = $this->kanvasAgent();
        $task = $this->makeTask($this->makePlan(['title' => 'Second Look planning'], $planner), TaskStatusEnum::IN_PROGRESS);
        $task->agent_id = $planner->getId();
        $task->description = $description;
        $task->saveOrFail();

        return $task;
    }

    private function askRepository(): AskRepositoryTool
    {
        return new AskRepositoryTool()->withContext(
            app(Apps::class),
            $this->user()->getCurrentCompany(),
            $this->user(),
        );
    }

    /**
     * @return list<string>
     */
    private function toolNamesOf(Agent $agent): array
    {
        $handler = new SystemUserAgent();
        $handler->setConfiguration($agent, user: $this->user());

        /** @var array<int, object> $tools */
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_values(array_map(fn (object $tool): string => (string) $tool->getName(), $tools));
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
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                int $timeout = 30
            ): array {
                $this->requests[] = [$method, $path, $body];
                $route = basename((string) parse_url($path, PHP_URL_PATH));

                return $this->payloads[$route] ?? [];
            }
        };
    }

    private function liveChatOnAMachine(Agent $agent, Session $chat, HarnessStatusEnum $status): AgentTaskSession
    {
        $live = $this->liveChat($agent, $chat, $status);
        $live->agent_machine_id = $this->machine()->getId();
        $live->saveOrFail();

        return $live;
    }

    /**
     * The factory every job and action reaches through `HarnessFactory::codingFactoryFor()`.
     */
    private function bindFactory(string $output): FactoryServeClient
    {
        $factory = $this->factory($output);
        $this->app->bind(FactoryServeClient::class, static fn (): FactoryServeClient => $factory);

        return $factory;
    }

    /**
     * Refuses the first request the way a stopped server does, then answers like `$transport`.
     */
    private function downOnce(HarnessTransport $transport): HarnessTransport
    {
        return new class ($transport) implements HarnessTransport {
            /** @var list<array{0: string, 1: string, 2: array<string, mixed>|null}> */
            public array $requests = [];

            private bool $down = true;

            public function __construct(
                private readonly HarnessTransport $transport,
            ) {
            }

            #[Override]
            public function request(
                string $method,
                string $path,
                ?array $body = null,
                int $timeout = 30
            ): array {
                if ($this->down) {
                    $this->down = false;

                    throw new RuntimeNotListeningException('The runtime is not listening (' . $method . ' ' . $path . ')');
                }

                $this->requests[] = [$method, $path, $body];

                return $this->transport->request(
                    $method,
                    $path,
                    $body,
                    $timeout
                );
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
