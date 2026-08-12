<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\CancelScheduledActionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ListScheduledActionsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ScheduleAgentTaskTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\ScheduleReminderTool;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionStatusEnum;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ScheduledActionToolsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    /**
     * @return array{0: Apps, 1: Companies, 2: Users}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        /** @var Users $user */
        $user = auth()->user();

        return [$app, $user->getCurrentCompany(), $user];
    }

    private function makeAgent(Apps $app, Companies $company, Users $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId()]);
    }

    private function futureLocal(int $days = 2): string
    {
        return Carbon::now()->addDays($days)->format('Y-m-d H:i');
    }

    public function testSystemUserAgentExposesSchedulingToolsByDefault(): void
    {
        [$app, $company, $user] = $this->context();

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent($app, $company, $user),
            entity: $user,
            user: $user,
        );

        $names = array_map(fn (object $tool): string => $tool->getName(), $handler->getTools());

        foreach ([
            'schedule_reminder',
            'schedule_agent_task',
            'list_scheduled_actions',
            'cancel_scheduled_action',
        ] as $expected) {
            $this->assertContains($expected, $names, "Every SystemUserAgent must expose {$expected} by default.");
        }
    }

    public function testScheduleReminderToolCreatesOneOffRow(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $tool = new ScheduleReminderTool($agent)->withContext($app, $company, $user);
        $result = $tool('Call the client back', $this->futureLocal());

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['recurring']);

        $row = ScheduledAction::query()->where('id', $result['scheduled_action_id'])->firstOrFail();
        $this->assertSame(ScheduledActionTypeEnum::REMINDER->value, $row->action_type);
        $this->assertSame(ScheduledActionStatusEnum::PENDING->value, $row->status);
        $this->assertSame($user->getId(), $row->users_id);
        $this->assertSame($agent->getId(), $row->agent_id);
    }

    public function testScheduleReminderToolCreatesRecurringRow(): void
    {
        [$app, $company, $user] = $this->context();

        $tool = new ScheduleReminderTool($this->makeAgent($app, $company, $user))->withContext($app, $company, $user);
        $result = $tool('Daily standup nudge', null, '0 9 * * 1-5');

        $this->assertSame('success', $result['status']);
        $this->assertTrue($result['recurring']);

        $row = ScheduledAction::query()->where('id', $result['scheduled_action_id'])->firstOrFail();
        $this->assertSame('0 9 * * 1-5', $row->recurrence_cron);
        $this->assertTrue($row->run_at->greaterThan(Carbon::now()));
    }

    public function testScheduleReminderRejectsPastTime(): void
    {
        [$app, $company, $user] = $this->context();

        $tool = new ScheduleReminderTool($this->makeAgent($app, $company, $user))->withContext($app, $company, $user);
        $result = $tool('Too late', Carbon::now()->subDay()->format('Y-m-d H:i'));

        $this->assertSame('error', $result['status']);
    }

    public function testScheduleReminderToTeammateResolvesMemberAndRejectsOutsider(): void
    {
        [$app, $company, $user] = $this->context();
        $tool = new ScheduleReminderTool($this->makeAgent($app, $company, $user))->withContext($app, $company, $user);

        // A real member (the current user's own email) resolves.
        $ok = $tool('Ping', $this->futureLocal(), null, null, null, (string) $user->email);
        $this->assertSame('success', $ok['status']);
        $this->assertSame($user->email, $ok['recipient']);

        // An outside address is rejected — never a free-typed recipient.
        $bad = $tool('Ping', $this->futureLocal(), null, null, null, 'outsider@nowhere.test');
        $this->assertSame('error', $bad['status']);
    }

    public function testScheduleAgentTaskToolCreatesRow(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $tool = new ScheduleAgentTaskTool($agent)->withContext($app, $company, $user);
        $result = $tool('Email the client the updated quote', $this->futureLocal());

        $this->assertSame('success', $result['status']);
        $row = ScheduledAction::query()->where('id', $result['scheduled_action_id'])->firstOrFail();
        $this->assertSame(ScheduledActionTypeEnum::AGENT_TASK->value, $row->action_type);
        $this->assertSame('Email the client the updated quote', $row->payload['instruction']);
    }

    public function testScheduleAgentTaskRejectsSubFifteenMinuteCron(): void
    {
        [$app, $company, $user] = $this->context();

        $tool = new ScheduleAgentTaskTool($this->makeAgent($app, $company, $user))->withContext($app, $company, $user);
        $result = $tool('Poll the queue', null, '*/5 * * * *');

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            0,
            ScheduledAction::query()->where('apps_id', $app->getId())
                ->where('action_type', ScheduledActionTypeEnum::AGENT_TASK->value)
                ->where('recurrence_cron', '*/5 * * * *')
                ->count(),
        );
    }

    public function testListAndCancelTools(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $created = new ScheduleReminderTool($agent)->withContext($app, $company, $user)('Review the report', $this->futureLocal());
        $id = $created['scheduled_action_id'];

        $list = new ListScheduledActionsTool()->withContext($app, $company, $user)();
        $this->assertSame('success', $list['status']);
        $this->assertContains($id, array_column($list['actions'], 'id'));

        $cancel = new CancelScheduledActionTool()->withContext($app, $company, $user)($id);
        $this->assertSame('success', $cancel['status']);
        $this->assertSame(
            ScheduledActionStatusEnum::CANCELLED->value,
            ScheduledAction::query()->where('id', $id)->value('status'),
        );

        // An unknown id is a structured error, never a thrown exception into the chat.
        $missing = new CancelScheduledActionTool()->withContext($app, $company, $user)(999999999);
        $this->assertSame('error', $missing['status']);
    }
}
