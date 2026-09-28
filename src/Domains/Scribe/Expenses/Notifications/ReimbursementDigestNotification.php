<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Notifications;

use Illuminate\Support\Facades\View;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Override;

/**
 * Mail-only: tells one employee what the company still owes them for expenses they paid personally.
 *
 * The Companies model is the entity anchor — the Kanvas Notification base resolves $this->app from
 * $entity->app via KanvasModelTrait. One of these is dispatched per employee by
 * SendReimbursementDigestAction; the base class queues it.
 */
class ReimbursementDigestNotification extends Notification
{
    private const string VIEW = 'emails.scribe.reimbursement-digest';

    public array $channels = ['mail'];

    /**
     * @param  array<int, array<string, mixed>>  $months per-month rows owed to this employee
     */
    public function __construct(
        Companies $company,
        string $employeeName,
        string $asOf,
        float $totalOwed,
        string $currency,
        int $expenseCount,
        int $daysOutstanding,
        array $months,
    ) {
        parent::__construct($company, [
            'company' => $company,
        ]);

        $this->setSubject(sprintf(
            '%s owes you %s %s in expense reimbursements',
            $company->name,
            number_format($totalOwed, 2),
            $currency,
        ));

        $this->setData([
            'company_name' => $company->name,
            'employee_name' => $employeeName,
            'as_of' => $asOf,
            'total_owed' => $totalOwed,
            'currency' => $currency,
            'expense_count' => $expenseCount,
            'days_outstanding' => $daysOutstanding,
            'months' => $months,
        ]);
    }

    #[Override]
    public function getEmailContent(): string
    {
        return View::make(self::VIEW, $this->getData())->render();
    }
}
