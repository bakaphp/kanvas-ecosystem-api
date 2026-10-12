<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Slack\Enums\ConfigurationEnum as SlackConfigurationEnum;
use Kanvas\Connectors\Slack\Enums\CustomFieldEnum as SlackCustomFieldEnum;
use Kanvas\Connectors\Slack\Webhooks\ProcessSlackWebhookJob;
use Kanvas\Intelligence\AgentRuntime\Enums\AgentChannelTokenEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\SnoozeNervousSystemTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendEmailToUserTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\SendSlackDirectMessageTool;
use Kanvas\NervousSystem\Plan\Actions\RecordTaskAskAction;
use Kanvas\NervousSystem\Plan\Actions\SnoozeTaskAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use ReflectionMethod;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * A reply from the person a task waits on reaches the agent with the task in hand: the ask records
 * who owes the answer, the person's next turn lists what they owe, and the wake names the thread.
 */
class ReplyMatchingTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'ecosystem', 'intelligence', 'social'];

    public function test_snoozing_with_the_person_records_who_the_task_waits_on(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $charlie = static::$cachedUser;

        $result = $this->snoozeTool($plan)(
            task_id: $task->getId(),
            reason: 'Charlie: strategy tomorrow',
            waiting_on_email: $charlie->email,
        );

        $this->assertTrue($result['success']);
        $this->assertSame($charlie->getId(), $task->refresh()->waiting_on_users_id);
        $this->assertArrayNotHasKey('also_waiting_on_this_person', $result);

        $wake = ScheduledAction::query()->findOrFail($result['scheduled_action_id']);
        $this->assertStringContainsString('You are waiting on ' . $charlie->email, $wake->payload['instruction']);
    }

    public function test_an_unknown_email_snoozes_but_says_nobody_was_recorded(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $result = $this->snoozeTool($plan)(
            task_id: $task->getId(),
            reason: 'tomorrow',
            waiting_on_email: 'nobody-' . uniqid() . '@example.test',
        );

        $this->assertTrue($result['success']);
        $this->assertSame(TaskStatusEnum::WAITING->value, $task->refresh()->status);
        $this->assertNull($task->waiting_on_users_id);
        $this->assertArrayHasKey('waiting_on_warning', $result);
    }

    public function test_emailing_a_teammate_for_a_task_records_them_and_the_channel_on_it(): void
    {
        Mail::fake();
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $charlie = static::$cachedUser;

        $result = new SendEmailToUserTool($plan->agent)(
            recipient_email: $charlie->email,
            subject: 'Q3 strategy',
            body: 'Could you send the deck?',
            task_id: $task->getId(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($task->getId(), $result['task_id']);
        $task->refresh();
        $this->assertSame($charlie->getId(), $task->waiting_on_users_id);
        $this->assertNotNull($task->waiting_on_channel_id);
        $this->assertDatabaseHas(
            'nervous_system_events',
            [
                'source_entity_type' => Task::class,
                'source_entity_id' => $task->getId(),
                'event_type' => 'plan.task.ask_sent',
            ],
            'intelligence',
        );
    }

    /** Scenario 12: a second ask to the same person is flagged, not silently filed beside the first. */
    public function test_a_second_ask_to_the_same_person_names_the_asks_already_open_with_them(): void
    {
        $plan = $this->makePlan();
        $charlie = static::$cachedUser;
        $first = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $first->title = 'Get the Q3 deck';
        $first->saveQuietly();
        new RecordTaskAskAction($first, $charlie)->execute();
        $second = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);

        $result = $this->snoozeTool($plan)(
            task_id: $second->getId(),
            reason: 'Charlie: numbers Friday',
            waiting_on_email: $charlie->email,
        );

        $this->assertSame(['#' . $first->getId() . ' Get the Q3 deck'], $result['also_waiting_on_this_person']);
        $this->assertStringContainsString('ONE message', $result['guidance']);
    }

    public function test_the_waited_on_person_sees_the_tasks_they_owe_in_the_agent_context(): void
    {
        $agent = $this->makeAgent();
        $agent->type->update(['handler' => SalesNeuronAgentStub::class]);
        $plan = $this->makePlan([], $agent->refresh());
        $charlie = static::$cachedUser;
        $owed = $this->makeTask($plan, TaskStatusEnum::WAITING);
        $owed->title = 'Get the Q3 deck';
        $owed->blocked_reason = 'Charlie: will send the deck tomorrow';
        $owed->wake_at = Carbon::now()->addDay();
        $owed->saveQuietly();
        new RecordTaskAskAction($owed, $charlie)->execute();
        $unrelated = $this->makeTask($plan, TaskStatusEnum::WAITING);
        new RecordTaskAskAction($unrelated, $this->anotherHuman())->execute();

        $lines = $this->contextLinesFor($agent, $charlie);

        $this->assertNotSame([], $lines);
        $joined = implode("\n", $lines);
        $this->assertStringContainsString('task #' . $owed->getId() . ' "Get the Q3 deck"', $joined);
        $this->assertStringContainsString('Charlie: will send the deck tomorrow', $joined);
        $this->assertStringNotContainsString('task #' . $unrelated->getId() . ' ', $joined);
        $this->assertStringContainsString('A reply arriving is not, by itself, the answer', $joined);
    }

    public function test_a_person_who_owes_nothing_gets_no_waiting_block(): void
    {
        $agent = $this->makeAgent();
        $agent->type->update(['handler' => SalesNeuronAgentStub::class]);
        $this->makePlan([], $agent->refresh());

        $this->assertSame([], $this->contextLinesFor($agent, static::$cachedUser));
    }

    public function test_closing_the_task_keeps_who_answered_but_the_wake_is_gone(): void
    {
        $plan = $this->makePlan();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        new RecordTaskAskAction($task, static::$cachedUser)->execute();
        $wake = new SnoozeTaskAction(
            task: $task,
            agent: $plan->agent,
            until: Carbon::now()->addDay(),
            reason: 'tomorrow',
        )->execute();

        $task->refresh();
        $this->assertSame(1, Task::query()->whereKey($task->getId())->waitingOnPerson(static::$cachedUser)->count());

        new UpdateTaskStatusAction(task: $task, newStatus: TaskStatusEnum::DONE)->execute();

        $this->assertSame(0, Task::query()->whereKey($task->getId())->waitingOnPerson(static::$cachedUser)->count());
        $this->assertSame('cancelled', $wake->refresh()->status);
    }

    /** Decision 1: the ask goes out in front of whoever wanted the answer, in the thread the wake re-reads. */
    public function test_an_ask_on_slack_opens_a_group_dm_with_the_asker_and_records_that_thread(): void
    {
        $plan = $this->makePlan();
        $asker = $this->anotherHuman();
        $plan->origin_users_id = $asker->getId();
        $plan->saveQuietly();
        $task = $this->makeTask($plan, TaskStatusEnum::IN_PROGRESS);
        $charlie = static::$cachedUser;
        $agent = $this->connectAgentToSlack($plan->agent, 'TTEAM1');
        Http::fake([
            'slack.com/api/users.lookupByEmail' => fn (Request $request): PromiseInterface => Http::response([
                'ok' => true,
                'user' => ['id' => $request['email'] === $charlie->email ? 'UCHARLIE' : 'UASKER'],
            ]),
            'slack.com/api/conversations.open' => Http::response(['ok' => true, 'channel' => ['id' => 'G777']]),
            'slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1700000000.000100']),
        ]);

        $result = new SendSlackDirectMessageTool($agent)(
            recipient_email: $charlie->email,
            message: 'Could you send the Q3 deck?',
            task_id: $task->getId(),
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame($asker->email, $result['group_dm_with']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'conversations.open')
            && $request['users'] === 'UCHARLIE,UASKER');
        $task->refresh();
        $this->assertSame($charlie->getId(), $task->waiting_on_users_id);
        $channel = Channel::query()->findOrFail($task->waiting_on_channel_id);
        $this->assertSame('slack-tteam1-g777', $channel->slug);
        $this->assertSame('Could you send the Q3 deck?', $channel->messages()->first()->message['content']);
    }

    private function connectAgentToSlack(Agent $agent, string $teamId): Agent
    {
        $agent->set(AgentChannelTokenEnum::SLACK_BOT_TOKEN->value, 'xoxb-test-token');
        $action = WorkflowAction::firstOrCreate(
            ['model_name' => ProcessSlackWebhookJob::class],
            ['name' => 'ProcessSlackWebhookJob']
        );
        $receiver = ReceiverWebhook::factory()
            ->app($agent->apps_id)
            ->user($agent->user->getId())
            ->company($agent->companies_id)
            ->create([
                'action_id' => $action->getId(),
                'configuration' => [
                    SlackConfigurationEnum::AGENT_ID->value => $agent->getId(),
                    SlackConfigurationEnum::TEAM_ID->value => $teamId,
                ],
            ]);
        $agent->set(SlackCustomFieldEnum::RECEIVER_ID->value, $receiver->getId());

        return $agent;
    }

    private function snoozeTool($plan): SnoozeNervousSystemTaskTool
    {
        return new SnoozeNervousSystemTaskTool($plan->agent)->withContext(
            app(Apps::class),
            static::$cachedUser->getCurrentCompany(),
            static::$cachedUser,
        );
    }

    /**
     * @return list<string>
     */
    private function contextLinesFor($agent, Users $human): array
    {
        $neuron = new SalesNeuronAgentStub();
        $neuron->setConfiguration($agent, $human, $human);
        $neuron->setConversationHuman($human);

        return new ReflectionMethod($neuron, 'waitingOnPersonLines')->invoke($neuron);
    }

    private function anotherHuman(): Users
    {
        return Users::factory()->create(['email' => 'other-' . uniqid() . '@example.test']);
    }
}
