<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Issues the document the company owes its tax authority for something it bought or paid, where the regime has one
 * (DR: E41 on a bill from a supplier who issues none, E43 on a minor expense, E47 on a payment abroad). Same
 * allocate → submit path as an invoice (EcfIssuanceService), same agent gate in front of it, but nothing posts:
 * the purchase is already booked by the bill or expense; the document only reports it.
 *
 * The type comes from the caller or, for one an approver signed, from the draft type the request froze. A burned
 * number (rejected and counted by the authority) is issued again here on a new fiscal row.
 */
class IssuePurchaseEcfAction
{
    public function __construct(
        public readonly Bill|Expense $document,
        public readonly ?FiscalDocumentTypeInterface $type = null,
        protected readonly EcfIssuanceService $ecf = new EcfIssuanceService(),
    ) {
    }

    public function execute(): Bill|Expense
    {
        $document = $this->document;

        if (EcfCompliance::hasNumber($document)) {
            return $document;
        }

        $type = self::assertIssuable($document, $this->type, $this->ecf);
        $this->ecf->assertMayIssuePurchase($document);
        $this->ecf->assertReadyToIssue($document, $type);

        $document = DB::connection('accounting')->transaction(function () use ($document, $type): Bill|Expense {
            $this->ecf->assignPurchaseNumber($document, $type);
            $document->save();

            $document->emitLedgerEvent(
                eventType: EcfCompliance::eventName($document, 'ecf_issued'),
                payload: [
                    'encf' => EcfCompliance::number($document),
                    'type' => $type->code(),
                    'vendor_display_name' => $document->vendor_display_name,
                    'currency' => $document->currency,
                    'total_native' => (float) $document->total_native,
                ],
            );

            return $document->refresh();
        });

        $this->ecf->dispatchSubmission($document);

        return $document;
    }

    /**
     * The checks shared with submitting for approval, so a request that could never be issued is never opened.
     */
    public static function assertIssuable(
        Bill|Expense $document,
        ?FiscalDocumentTypeInterface $type,
        EcfIssuanceService $ecf = new EcfIssuanceService()
    ): FiscalDocumentTypeInterface {
        if (! $ecf->issuesEcfFor($document->company)) {
            throw new ValidationException('This company issues no electronic invoices, so there is no purchase document to issue.');
        }

        $received = $document instanceof Bill ? ReceivedEcfCompliance::current($document) : null;

        if ($received !== null) {
            throw new ValidationException(
                "Bill {$document->getId()} is the vendor's own electronic invoice ({$received->number}). A purchase "
                . 'document is only for suppliers who issue none, so nothing is issued on it.'
            );
        }

        $compliance = EcfCompliance::read($document);

        $regime = $ecf->regimeFor($document->company);
        $type ??= $regime->documentType((string) ($compliance['type'] ?? ''));
        $allowed = $regime->purchaseDocumentTypes($document);

        if ($allowed === []) {
            throw new ValidationException("The {$regime->country()} tax authority has no document a company issues for what it buys.");
        }

        if ($type === null || ! in_array($type, $allowed, true)) {
            $names = implode(', ', array_map(fn (FiscalDocumentTypeInterface $allowedType): string => $allowedType->series(), $allowed));

            throw new ValidationException('A ' . strtolower(class_basename($document)) . " can only be issued as {$names}.");
        }

        $booked = $document instanceof Bill
            ? in_array($document->document_status, [BillDocumentStatusEnum::RECEIVED, BillDocumentStatusEnum::PAID], true)
            : $document->status === ExpenseStatusEnum::APPROVED;

        if (! $booked) {
            throw new ValidationException(
                'Only a booked ' . strtolower(class_basename($document)) . ' (a received or paid bill, an approved expense) '
                . 'can carry an e-CF; approve it first.'
            );
        }

        return $type;
    }
}
