<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Approvals\Actions\SystemApproveAction;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Approvals\PurchaseEcfApprovalPayload;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Parks a purchase e-CF (E41/E43/E47) for a person's sign-off, the purchase-side twin of
 * SubmitInvoiceForApprovalAction. The chosen type is written to `do_ecf.type` before the request opens, so
 * the approver signs the type that will be issued and the handler reads it back.
 *
 * A request no step applies to (a person submitting) is closed by rule and the e-CF is issued at once.
 */
class SubmitPurchaseEcfForApprovalAction
{
    public function __construct(
        public readonly Bill|Expense $document,
        public readonly EcfTypeEnum $type,
        public readonly ?UserInterface $user = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Bill|Expense
    {
        $document = $this->document;

        if (EcfCompliance::hasNumber($document)) {
            throw new ValidationException('This document already carries an e-CF (' . EcfCompliance::number($document) . ').');
        }

        IssuePurchaseEcfAction::assertIssuable($document, $this->type, $this->ecf);

        if ($document->pendingApproval(EcfIssuanceService::PURCHASE_APPROVAL_TYPE) !== null) {
            return $document;
        }

        EcfCompliance::writeDraftSettings($document, ['type' => $this->type->value]);
        $document->save();

        $request = $document->requestApproval(
            EcfIssuanceService::PURCHASE_APPROVAL_TYPE,
            payload: PurchaseEcfApprovalPayload::for($document),
            requestedBy: $this->user,
            origin: ApprovalOriginService::current(),
        ) ?? throw new ValidationException(
            'This company has no purchase e-CF approval policy, so nobody could approve it. '
            . 'Seed one with kanvas-scribe:seed-ecf-approval-policy.'
        );

        if ((bool) ($request->metadata['no_live_steps'] ?? false)) {
            new SystemApproveAction(
                $request,
                "No approval step applies to a purchase e-CF from origin '{$request->origin->value}'."
            )->execute();
        }

        return $document->refresh();
    }
}
