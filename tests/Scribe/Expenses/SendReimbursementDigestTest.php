<?php

declare(strict_types=1);

namespace Tests\Scribe\Expenses;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Actions\SendReimbursementDigestAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Notifications\ReimbursementDigestNotification;
use Tests\Scribe\ScribeTestCase;

final class SendReimbursementDigestTest extends ScribeTestCase
{
    public function test_sends_one_email_per_employee_still_owed(): void
    {
        NotificationFacade::fake();

        $alice = $this->seedTestEmployee('digest-employee');
        $bob = $this->seedTestEmployee('digest-employee');

        $this->approveTestExpense(300.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $alice->getId());
        $this->approveTestExpense(75.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $bob->getId());

        $result = $this->digest();

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['skipped']);
        NotificationFacade::assertSentTo($alice, ReimbursementDigestNotification::class);
        NotificationFacade::assertSentTo($bob, ReimbursementDigestNotification::class);
    }

    public function test_does_not_email_someone_already_reimbursed(): void
    {
        NotificationFacade::fake();

        $employee = $this->seedTestEmployee('digest-employee');
        $expense = $this->approveTestExpense(200.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL, $employee->getId());
        new RecordExpenseReimbursementAction(expense: $expense, user: static::$cachedUser)->execute();

        $this->assertSame(0, $this->digest()['sent']);
        NotificationFacade::assertNothingSent();
    }

    public function test_does_not_email_for_company_paid_spending(): void
    {
        NotificationFacade::fake();

        $this->approveTestExpense(500.00, ExpensePaidByEnum::COMPANY_CARD);

        $this->assertSame(0, $this->digest()['sent']);
    }

    /**
     * The liability is real even with nobody to email, so it is counted rather than dropped — a
     * silent skip is how an unattributed expense stays unpaid forever.
     */
    public function test_counts_an_unattributed_liability_as_skipped(): void
    {
        NotificationFacade::fake();

        $this->approveTestExpense(120.00, ExpensePaidByEnum::EMPLOYEE_PERSONAL);

        $result = $this->digest();

        $this->assertSame(0, $result['sent']);
        $this->assertSame(1, $result['skipped']);
    }

    /**
     * @return array{sent: int, skipped: int}
     */
    private function digest(): array
    {
        return new SendReimbursementDigestAction(
            $this->kanvasApp,
            $this->company,
            Carbon::parse('2026-06-30'),
        )->execute();
    }
}
