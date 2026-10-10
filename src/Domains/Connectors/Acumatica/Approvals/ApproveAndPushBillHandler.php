<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Override;

/**
 * Approves an AP bill in Kanvas. Status-transition workflows handle external synchronization.
 */
class ApproveAndPushBillHandler implements ApprovalHandlerInterface
{
    use ReadsApprovalSourceFields;

    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        /** @var Bill|null $bill */
        $bill = $request->resolveEntity();

        if ($bill === null) {
            throw new ValidationException("Bill {$request->entity_id} no longer exists.");
        }

        if ($approver === null) {
            throw new ValidationException('Approving a bill requires an approving user.');
        }

        $bill = new ApproveBillAction($bill, $bill->vendor, $approver)->execute();

        $result = [
            'target_type' => 'bill',
            'target_id' => $bill->getId(),
            'label' => $bill->bill_number,
            ...$this->sourceFields($bill),
        ];

        return $result;
    }
}
