<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CancelMyExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractExpenseReceiptTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SubmitMyExpenseTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\WhatDoesTheCompanyOweMeTool;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseReimbursementStatusEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\ScribeTestCase;

final class SubmitMyExpenseToolTest extends ScribeTestCase
{
    public function test_files_the_expense_as_paid_by_the_caller_and_submits_it(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');

        $result = $this->submit($employee, 145.50, 'Dinner with ACME while closing the renewal');

        $this->assertSame('submitted', $result['status'], (string) ($result['message'] ?? ''));
        $this->assertEqualsWithDelta(145.50, $result['total'], 0.005);

        $expense = Expense::getById($result['expense_id']);
        $this->assertSame(ExpensePaidByEnum::EMPLOYEE_PERSONAL, $expense->paid_by);
        $this->assertSame($employee->getId(), (int) $expense->paid_by_users_id);
        $this->assertSame(ExpenseStatusEnum::PENDING_APPROVAL, $expense->status);
        $this->assertSame(ExpenseReimbursementStatusEnum::PENDING, $expense->reimbursement_status);
    }

    /**
     * paid_by is the security property, not a default: a model that asks for a company-card expense,
     * or for one in someone else's name, must still get one owed to the caller.
     */
    public function test_paid_by_is_not_negotiable_by_the_model(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');
        $someoneElse = $this->seedTestEmployee('submit-expense');

        $result = new SubmitMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(
                amount: 60.00,
                description: 'Put this on the company card for ' . $someoneElse->getId(),
            );

        $expense = Expense::getById($result['expense_id']);
        $this->assertSame(ExpensePaidByEnum::EMPLOYEE_PERSONAL, $expense->paid_by);
        $this->assertSame($employee->getId(), (int) $expense->paid_by_users_id);
        $this->assertNotSame($someoneElse->getId(), (int) $expense->paid_by_users_id);
    }

    public function test_records_the_merchant_on_the_expense(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');

        $result = $this->submit($employee, 60.00, 'Client dinner');

        $this->assertSame('La Cassina', Expense::getById($result['expense_id'])->vendor_display_name);
    }

    public function test_splits_tax_out_of_the_total_rather_than_adding_to_it(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');

        $result = $this->submit($employee, 118.00, 'Hotel night', taxAmount: 18.00);

        $expense = Expense::getById($result['expense_id']);
        $this->assertEqualsWithDelta(118.00, (float) $expense->total_native, 0.005);
        $this->assertEqualsWithDelta(100.00, (float) $expense->subtotal_native, 0.005);
        $this->assertEqualsWithDelta(18.00, (float) $expense->tax_native, 0.005);
    }

    public function test_attaches_the_receipt_when_one_is_given(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');
        $receipt = $this->createFilesystemRow();

        $result = $this->submit($employee, 45.00, 'Taxi to airport', filesystemId: (int) $receipt->getKey());

        $this->assertArrayNotHasKey('attachment_warning', $result);
        $this->assertSame(1, Expense::getById($result['expense_id'])->receipts()->count());
    }

    public function test_reports_a_missing_receipt_without_losing_the_expense(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');

        $result = $this->submit($employee, 45.00, 'Taxi to airport', filesystemId: 99999999);

        $this->assertSame('submitted', $result['status']);
        $this->assertStringContainsString('99999999', $result['attachment_warning']);
    }

    /**
     * A tool wired through addToolContext() is handed actingUser(), which on a SystemUserAgent is the
     * AGENT'S user — so "the caller" is routinely a bot. Filing then books a Due to Employees credit
     * owed to something that is not a person, which nobody claims and which breaks the reconciliation
     * between the ledger and the employee report.
     */
    public function test_refuses_to_file_an_expense_in_an_agents_name(): void
    {
        $agentUser = $this->seedTestEmployee('agent-identity');
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Polly', 'user_id' => $agentUser->getId()]);

        $result = new SubmitMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $agentUser, $agent)
            ->__invoke(amount: 145.50, description: 'Dinner the bot did not eat');

        $this->assertFalse($result['success']);
        $this->assertSame('agent_caller', $result['status']);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $result['outcome']);
        $this->assertSame(0, Expense::query()->where('paid_by_users_id', $agentUser->getId())->count());
    }

    public function test_refuses_to_withdraw_an_expense_as_an_agent(): void
    {
        $agentUser = $this->seedTestEmployee('agent-identity');
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Polly', 'user_id' => $agentUser->getId()]);

        $expense = $this->draftTestExpense(60.00, $agentUser->getId());

        $result = new CancelMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $agentUser, $agent)
            ->__invoke(expense_id: $expense->getId());

        $this->assertFalse($result['success']);
        $this->assertSame('agent_caller', $result['status']);
        $this->assertSame(ExpenseStatusEnum::DRAFT, Expense::getById($expense->getId())->status);
    }

    /**
     * An agent's user is routinely a real person's — one user backs 28 agents in production, and on a
     * dev box it is usually the developer's own login. Treating "this user backs an agent" as "this
     * caller is a bot" locks that person out of their own expenses, which is the live failure this
     * replaced: the check is now whether the AGENT RUNNING THE TURN owns the identity, not whether
     * some agent somewhere does.
     */
    public function test_a_person_whose_user_also_backs_an_agent_can_still_file(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');
        Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'Polly', 'user_id' => $employee->getId()]);

        $result = $this->submit($employee, 145.50, 'Dinner with ACME');

        $this->assertSame('submitted', $result['status'], (string) ($result['error'] ?? ''));
        $this->assertSame($employee->getId(), (int) Expense::getById($result['expense_id'])->paid_by_users_id);
    }

    /**
     * The live failure this exists for: a person chatting with the AP Clerk got refused, because a
     * catalog-granted tool is wired with actingUser() — the agent's own user — and never sees them.
     * MergesRegisteredTools hands the identified human over separately; that is who it must file for.
     */
    public function test_files_for_the_conversation_human_when_the_context_user_is_the_agent(): void
    {
        $agentUser = $this->seedTestEmployee('agent-identity');
        Agent::factory()
            ->withAppId($this->kanvasApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['name' => 'AP Clerk', 'user_id' => $agentUser->getId()]);

        $person = $this->seedTestEmployee('submit-expense');

        $result = new SubmitMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $agentUser)
            ->forConversationHuman($person)
            ->__invoke(amount: 4.19, description: 'Office hygiene supplies');

        $this->assertSame('submitted', $result['status'], (string) ($result['error'] ?? ''));
        $this->assertSame($person->getId(), (int) Expense::getById($result['expense_id'])->paid_by_users_id);
    }

    public function test_rejects_a_zero_amount(): void
    {
        $result = $this->submit($this->seedTestEmployee('submit-expense'), 0.0, 'Nothing');

        $this->assertSame('invalid_amount', $result['status']);
        $this->assertFalse($result['success'], 'A refusal must read as a failure, not just carry an error key.');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $result['outcome']);
    }

    /**
     * Keyed by tool name alone, filing a trip's worth of receipts would trip the run budget and kill
     * the turn on the 11th. Keyed by inputs, only a genuine repeat is capped.
     */
    public function test_run_budget_is_keyed_by_inputs_so_a_batch_of_receipts_does_not_trip_it(): void
    {
        $tool = new SubmitMyExpenseTool();
        $this->assertInstanceOf(HasRunKey::class, $tool);

        $tool->setInputs(['amount' => 45.0, 'description' => 'Taxi to airport']);
        $taxi = $tool->getRunKey();

        $tool->setInputs(['amount' => 145.5, 'description' => 'Dinner with ACME']);
        $dinner = $tool->getRunKey();

        $tool->setInputs(['amount' => 45.0, 'description' => 'Taxi to airport']);
        $taxiAgain = $tool->getRunKey();

        $this->assertNotEquals($taxi, $dinner, 'Distinct expenses must not share a run budget.');
        $this->assertEquals($taxi, $taxiAgain, 'Identical calls must collapse so a stuck loop is still capped.');
    }

    public function test_nothing_owed_is_labelled_a_noop_so_the_model_stops_retrying(): void
    {
        $result = new WhatDoesTheCompanyOweMeTool()
            ->withContext($this->kanvasApp, $this->company, $this->seedTestEmployee('submit-expense'))
            ->__invoke();

        $this->assertSame('nothing_owed', $result['status']);
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $result['outcome']);
    }

    public function test_fails_closed_without_a_user(): void
    {
        $result = new SubmitMyExpenseTool()->__invoke(amount: 10.0, description: 'x');

        $this->assertSame('no_tenant_context', $result['reason']);
    }

    public function test_submitted_expense_shows_up_in_what_the_company_owes_me_once_approved(): void
    {
        $employee = $this->seedTestEmployee('submit-expense');

        $result = $this->submit($employee, 145.50, 'Dinner with ACME');
        $expense = Expense::getById($result['expense_id']);

        $owed = new WhatDoesTheCompanyOweMeTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke();
        $this->assertSame('nothing_owed', $owed['status'], 'Nothing is owed until a manager approves it.');

        new ApproveExpenseAction(expense: $expense, approver: static::$cachedUser)->execute();

        $owed = new WhatDoesTheCompanyOweMeTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke();
        $this->assertSame('owed', $owed['status']);
        $this->assertEqualsWithDelta(145.50, $owed['total_owed'], 0.005);
    }

    public function test_extract_expense_receipt_flattens_the_classifier_payload(): void
    {
        $this->app->instance(
            PdfClassifierServiceInterface::class,
            new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
                confidence: 0.94,
                extracted: [
                    'vendor_name' => 'La Cassina',
                    'total' => 145.50,
                    'tax' => 22.50,
                    'currency' => 'USD',
                    'issue_date' => '2026-06-14',
                ],
            )),
        );

        $result = new ExtractExpenseReceiptTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(filesystem_id: (int) $this->createFilesystemRow()->getKey());

        $this->assertTrue($result['success']);
        $this->assertTrue($result['looks_like_a_receipt']);
        $this->assertSame('La Cassina', $result['merchant']);
        $this->assertEqualsWithDelta(145.50, $result['total'], 0.005);
        $this->assertEqualsWithDelta(123.00, $result['subtotal'], 0.005);
        $this->assertSame('2026-06-14', $result['expense_date']);
    }

    public function test_extract_expense_receipt_refuses_to_guess_a_missing_total(): void
    {
        $this->app->instance(
            PdfClassifierServiceInterface::class,
            new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::UNKNOWN,
                confidence: 0.2,
                extracted: ['vendor_name' => 'Illegible'],
            )),
        );

        $result = new ExtractExpenseReceiptTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(filesystem_id: (int) $this->createFilesystemRow()->getKey());

        $this->assertFalse($result['success']);
        $this->assertSame('no_total_found', $result['reason']);
    }

    /**
     * @return array<string, mixed>
     */
    private function submit(
        Users $employee,
        float $amount,
        string $description,
        ?float $taxAmount = null,
        ?int $filesystemId = null,
    ): array {
        return new SubmitMyExpenseTool()
            ->withContext($this->kanvasApp, $this->company, $employee)
            ->__invoke(
                amount: $amount,
                description: $description,
                expense_date: '2026-06-15',
                merchant: 'La Cassina',
                tax_amount: $taxAmount,
                filesystem_id: $filesystemId,
            );
    }
}
