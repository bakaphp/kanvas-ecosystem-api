<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Expenses\Enums;

/**
 * Reimbursement lifecycle (only meaningful when paid_by='employee_personal').
 *
 *   NOT_APPLICABLE → (everything else; for non-employee-paid expenses)
 *   PENDING        → APPROVED            (waiting for the approval flow to complete)
 *   APPROVED       → PAID                (approved but reimbursement hasn't fired yet)
 *   PAID           → (terminal — Due to Employees liability cleared by the reimbursement JE)
 */
enum ExpenseReimbursementStatusEnum: string
{
    case NOT_APPLICABLE = 'not_applicable';
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case PAID = 'paid';
}
