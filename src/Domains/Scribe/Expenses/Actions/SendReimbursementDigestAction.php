<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Expenses\Notifications\ReimbursementDigestNotification;
use Kanvas\Scribe\Reports\DataTransferObject\DueToEmployeesRow;
use Kanvas\Scribe\Reports\Repositories\DueToEmployeesRepository;
use Kanvas\Users\Models\Users;

/**
 * Per-employee digest: one email to each person the company still owes, telling them the total and
 * how it breaks down by month.
 *
 * Addressed to the employee rather than to Finance on purpose — the person owed the money is the one
 * who notices when a claim has silently gone nowhere, and they are the only recipient guaranteed to
 * care. Finance gets the same numbers on demand through query_due_to_employees.
 *
 * Rows with no `users_id` (an employee-paid expense nobody attributed) have nobody to email; they are
 * counted in the return value's `skipped` so a fan-out can surface that the data needs fixing rather
 * than silently dropping a real liability.
 */
class SendReimbursementDigestAction
{
    public function __construct(
        protected readonly AppInterface $app,
        protected readonly Companies $company,
        protected readonly Carbon $asOf,
    ) {
    }

    /**
     * @return array{sent: int, skipped: int}
     */
    public function execute(): array
    {
        $report = new DueToEmployeesRepository()->generate(
            app: $this->app,
            company: $this->company,
            asOf: $this->asOf,
        );

        $sent = 0;
        $skipped = 0;

        foreach ($report->rows as $row) {
            $recipient = $this->resolveRecipient($row);

            if ($recipient === null) {
                $skipped++;

                continue;
            }

            NotificationFacade::send(
                [$recipient],
                new ReimbursementDigestNotification(
                    $this->company,
                    $row->user_name ?? $recipient->email,
                    $this->asOf->toDateString(),
                    $row->total,
                    $report->currency,
                    $row->expense_count,
                    $row->days_outstanding,
                    $row->months->toArray(),
                ),
            );
            $sent++;
        }

        return ['sent' => $sent, 'skipped' => $skipped];
    }

    private function resolveRecipient(DueToEmployeesRow $row): ?Users
    {
        if ($row->users_id === null) {
            return null;
        }

        $user = Users::query()
            ->where('id', $row->users_id)
            ->where('is_deleted', 0)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->first();

        return $user instanceof Users ? $user : null;
    }
}
