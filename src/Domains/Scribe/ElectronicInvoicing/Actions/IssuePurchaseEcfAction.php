<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\ReceivedEcfCompliance;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;

/**
 * Issues the e-CF the company owes DGII for something it bought or paid: E41 on a bill from a supplier who
 * issues no e-CF, E43 on a minor expense, E47 on a payment to a foreign payee. Same allocate → submit path
 * as an invoice (EcfIssuanceService), same agent gate in front of it — but nothing posts, because the
 * purchase is already booked by the bill or expense; the e-CF only reports it.
 *
 * The type comes from the caller or, for one an approver signed, from the draft `do_ecf.type` the request froze.
 * A burned e-CF (DGII rejected and counted the number) is issued again here on a new fiscal row.
 */
class IssuePurchaseEcfAction
{
    private const array ALLOWED = [
        Bill::class => [EcfTypeEnum::COMPRAS, EcfTypeEnum::PAGOS_EXTERIOR],
        Expense::class => [EcfTypeEnum::GASTOS_MENORES, EcfTypeEnum::PAGOS_EXTERIOR],
    ];

    public function __construct(
        public readonly Bill|Expense $document,
        public readonly ?EcfTypeEnum $type = null,
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

        $document = DB::connection('accounting')->transaction(function () use ($document, $type): Bill|Expense {
            $this->ecf->assignPurchaseNumber($document, $type);
            $document->save();

            $document->emitLedgerEvent(
                eventType: EcfCompliance::eventName($document, 'ecf_issued'),
                payload: [
                    'encf' => EcfCompliance::number($document),
                    'type' => $type->value,
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
        ?EcfTypeEnum $type,
        EcfIssuanceService $ecf = new EcfIssuanceService()
    ): EcfTypeEnum {
        if (! $ecf->issuesEcfFor($document->company)) {
            throw new ValidationException('This company does not issue e-CF, so there is no purchase e-CF to issue.');
        }

        $received = $document instanceof Bill ? ReceivedEcfCompliance::current($document) : null;

        if ($received !== null) {
            throw new ValidationException(
                "Bill {$document->getId()} is the vendor's own e-CF ({$received->number}). E41 is only for suppliers "
                . 'who issue none, so nothing is issued on it.'
            );
        }

        $compliance = EcfCompliance::read($document);

        $type ??= EcfTypeEnum::tryFrom((string) ($compliance['type'] ?? ''));
        $allowed = self::ALLOWED[$document::class];

        if ($type === null || ! in_array($type, $allowed, true)) {
            $names = implode(', ', array_map(fn (EcfTypeEnum $allowedType): string => $allowedType->prefix(), $allowed));

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
