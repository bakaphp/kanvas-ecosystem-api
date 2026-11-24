<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Approvals;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Contracts\ApprovalHandlerInterface;
use Kanvas\Approvals\Models\ApprovalRequest;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\IssuePurchaseEcfAction;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;
use Override;

/**
 * Issues an approved purchase e-CF (E41/E43/E47) on its bill or expense. Refuses one that changed since it
 * was submitted, leaving it without a number, so what reaches DGII is what the approver saw.
 */
class IssuePurchaseEcfHandler implements ApprovalHandlerInterface
{
    #[Override]
    public function handle(ApprovalRequest $request, ?UserInterface $approver): array
    {
        $document = $request->resolveEntity();

        if (! $document instanceof Bill && ! $document instanceof Expense) {
            throw new ValidationException("The document behind approval request {$request->getId()} no longer exists.");
        }

        $frozen = $request->payload[PurchaseEcfApprovalPayload::HASH_KEY] ?? null;

        if (is_string($frozen) && $frozen !== '' && ! hash_equals($frozen, PurchaseEcfApprovalPayload::currentHash($document))) {
            throw new ValidationException(
                'The ' . strtolower(class_basename($document)) . ' changed after it was submitted for approval, so its e-CF was not '
                . 'issued. Submit it again so the approver signs what will actually be sent.'
            );
        }

        $document = new IssuePurchaseEcfAction($document)->execute();

        return [
            'target_type' => strtolower(class_basename($document)),
            'target_id' => $document->getId(),
            'encf' => EcfCompliance::number($document),
            'label' => EcfCompliance::number($document),
        ];
    }
}
