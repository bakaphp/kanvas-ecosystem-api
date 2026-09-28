<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Override;
use Throwable;

/**
 * The synchronous half of approving an AP bill: approve it in Kanvas, then push it to Acumatica.
 *
 * A push failure comes back as data (`pushed`, `push_error`), never as an exception: Apex reads those
 * to decide whether to mark the tracking sheet Approved, and a throw here would either lose the
 * recorded approval or have the agent report a success that never reached Acumatica.
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
            'pushed' => false,
            'reference' => null,
            'push_error' => null,
        ];

        try {
            $result['reference'] = new PushBillToAcumaticaAction($bill)->execute();
            $result['pushed'] = true;
            $result['acumatica_id'] = (string) $bill->get(CustomFieldEnum::BILL_ID->value, '');
        } catch (Throwable $e) {
            $result['push_error'] = $e->getMessage();
        }

        return $result;
    }
}
