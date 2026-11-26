<?php

declare(strict_types=1);

namespace Kanvas\Approvals\Contracts;

/**
 * Implemented by an approvable entity whose number or name alone does not tell an approver what they are
 * signing: a bill is "1001" for every vendor that uses that number. The approvals summary and the approval
 * emails add these details next to the label.
 */
interface DescribesApprovalDetailsInterface
{
    /**
     * `party` is who is paid or billed; `due_date` is Y-m-d.
     *
     * @return array{party: ?string, amount: ?float, currency: ?string, due_date: ?string}
     */
    public function approvalDetails(): array;
}
