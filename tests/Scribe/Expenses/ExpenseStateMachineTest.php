<?php

declare(strict_types=1);

namespace Tests\Scribe\Expenses;

use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Exceptions\InvalidExpenseTransitionException;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Services\ExpenseStateMachineService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExpenseStateMachineTest extends TestCase
{
    private ExpenseStateMachineService $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new ExpenseStateMachineService();
    }

    #[DataProvider('validTransitionProvider')]
    public function test_valid_transition_passes(ExpenseStatusEnum $from, ExpenseStatusEnum $to): void
    {
        $expense = new Expense();
        $expense->status = $from;
        $this->machine->assertTransition($expense, $to);
        $this->assertTrue(true, "{$from->value} → {$to->value} should be allowed.");
    }

    public static function validTransitionProvider(): array
    {
        return [
            'draft → pending_approval' => [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::PENDING_APPROVAL],
            'draft → voided' => [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::VOIDED],
            'pending → approved' => [ExpenseStatusEnum::PENDING_APPROVAL, ExpenseStatusEnum::APPROVED],
            'pending → rejected' => [ExpenseStatusEnum::PENDING_APPROVAL, ExpenseStatusEnum::REJECTED],
            'approved → voided (post reversal JE)' => [ExpenseStatusEnum::APPROVED, ExpenseStatusEnum::VOIDED],
            'approved → approved (idempotent)' => [ExpenseStatusEnum::APPROVED, ExpenseStatusEnum::APPROVED],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transition_throws(ExpenseStatusEnum $from, ExpenseStatusEnum $to): void
    {
        $expense = new Expense();
        $expense->status = $from;
        $this->expectException(InvalidExpenseTransitionException::class);
        $this->machine->assertTransition($expense, $to);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'draft → approved (must go via pending_approval)' => [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::APPROVED],
            'draft → rejected (rejection only after submit)' => [ExpenseStatusEnum::DRAFT, ExpenseStatusEnum::REJECTED],
            'pending → voided (must approve first)' => [ExpenseStatusEnum::PENDING_APPROVAL, ExpenseStatusEnum::VOIDED],
            'rejected → anything' => [ExpenseStatusEnum::REJECTED, ExpenseStatusEnum::APPROVED],
            'voided → anything' => [ExpenseStatusEnum::VOIDED, ExpenseStatusEnum::APPROVED],
            'approved → rejected (post-approval rejection invalid)' => [ExpenseStatusEnum::APPROVED, ExpenseStatusEnum::REJECTED],
        ];
    }
}
