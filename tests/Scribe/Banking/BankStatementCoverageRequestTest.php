<?php

declare(strict_types=1);

namespace Tests\Scribe\Banking;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Scheduling\Enums\ScheduledActionTypeEnum;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Scribe\Banking\Actions\IngestBankStatementAction;
use Kanvas\Scribe\Banking\Actions\RequestBankStatementAction;
use Kanvas\Scribe\Banking\DataTransferObject\ParsedBankStatement;
use Kanvas\Scribe\Banking\Enums\BankStatementSourceEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;

final class BankStatementCoverageRequestTest extends BankStatementTestCase
{
    private BankAccount $account;

    protected function afterScribeSetUp(): void
    {
        $this->account = $this->statementAccount('4321', 'DOP', 'Popular DOP');
    }

    public function testABehindAccountGetsTheApAgentAskingItsOwner(): void
    {
        $agent = $this->apAgent();
        $this->coverThrough('2026-05-20');

        $task = new RequestBankStatementAction($this->account)->execute();

        $this->assertInstanceOf(ScheduledAction::class, $task);
        $this->assertSame(ScheduledActionTypeEnum::AGENT_TASK->value, $task->action_type);
        $this->assertSame($agent->getId(), $task->agent_id);
        $this->assertSame(static::$cachedUser->getId(), $task->users_id, 'The account owner is asked.');
        $this->assertStringContainsString('from 2026-05-21', $task->payload['instruction']);
        $this->assertStringContainsString('bank_account_id ' . $this->account->getId(), $task->payload['instruction']);

        $this->assertTrue(
            Event::query()
                ->where('event_type', 'accounting.bank_statement.requested')
                ->where('source_entity_id', $this->account->getId())
                ->exists(),
        );
    }

    public function testItAsksOnlyOncePerCooldown(): void
    {
        $this->apAgent();

        $this->assertNotNull(new RequestBankStatementAction($this->account)->execute());
        $this->assertNull(new RequestBankStatementAction($this->account->refresh())->execute(), 'The 20th does not stack on the 10th.');

        Carbon::setTestNow(Carbon::now()->addDays(RequestBankStatementAction::REQUEST_COOLDOWN_DAYS + 1));
        $this->assertNotNull(new RequestBankStatementAction($this->account->refresh())->execute());
    }

    public function testWithoutAnApAgentTheGapIsStillRecorded(): void
    {
        $this->assertNull(new RequestBankStatementAction($this->account)->execute());

        $event = Event::query()
            ->where('event_type', 'accounting.bank_statement.requested')
            ->where('source_entity_id', $this->account->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertFalse($event->payload['delivered_to_agent']);
    }

    public function testTheCommandSkipsCoveredAndApiFedAccounts(): void
    {
        $this->apAgent();
        $this->coverThrough('2026-05-31');

        $mercury = $this->statementAccount('9999', 'USD', 'Mercury');
        $mercury->source = 'mercury';
        $mercury->save();

        $this->artisan('scribe:request-bank-statements')->assertSuccessful();

        $this->assertSame(0, $this->tasksFor($this->account), 'Covered to May 31 on June 1 is not behind.');
        $this->assertSame(0, $this->tasksFor($mercury), 'An API-fed account never needs a statement.');

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));
        $this->artisan('scribe:request-bank-statements')->assertSuccessful();

        $this->assertSame(1, $this->tasksFor($this->account));
    }

    private function coverThrough(string $date): void
    {
        new IngestBankStatementAction(
            app: $this->kanvasApp,
            company: $this->company,
            statement: new ParsedBankStatement(
                lines: [$this->line($date, 100.00, 1_100.00)],
                periodEnd: Carbon::parse($date),
            ),
            source: BankStatementSourceEnum::CSV,
            bankAccount: $this->account,
            user: static::$cachedUser,
        )->execute();
    }

    private function apAgent(): Agent
    {
        $type = AgentType::query()->where('handler', AccountsPayableAgent::class)->first()
            ?? AgentType::factory()->withAppId($this->kanvasApp->getId())->create([
                'provider' => 'neuron',
                'handler' => AccountsPayableAgent::class,
            ]);

        return Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'is_active' => true,
            ]);
    }

    private function tasksFor(BankAccount $account): int
    {
        return ScheduledAction::query()
            ->where('source_entity_type', BankAccount::class)
            ->where('source_entity_id', (string) $account->getId())
            ->count();
    }
}
