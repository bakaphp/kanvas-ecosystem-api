<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Services;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalStatusEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Exceptions\DocumentSequenceUnavailableException;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Actions\CreateFiscalDocumentAction;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfReference;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalRangeStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalNumberRange;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrEcfValidator;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\BuyerTaxId;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfExchangeRate;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Regional\DataTransferObject\TaxpayerLookup;
use Kanvas\Scribe\Regional\Services\TaxpayerLookupService;
use Throwable;

/**
 * The e-CF half of issuing an AR document, for a company with a provider set. The issue actions call it;
 * a company without a provider never reaches it and keeps its plain numbering.
 *
 * Order matters and is the whole point: refuse the agent without approval, validate, and only then take
 * the next e-NCF — so neither a missing approval nor a document DGII would reject ever spends a number.
 */
class EcfIssuanceService
{
    public const string PURCHASE_APPROVAL_TYPE = 'approve_purchase_ecf';

    public function __construct(
        protected readonly EcfProviderResolver $providers = new EcfProviderResolver(),
        protected readonly EcfTypeResolver $types = new EcfTypeResolver(),
        protected readonly EcfDocumentBuilder $builder = new EcfDocumentBuilder(),
        protected readonly DrEcfValidator $validator = new DrEcfValidator(),
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
    ) {
    }

    public function issuesEcf(Invoice $invoice): bool
    {
        return $this->issuesEcfFor($invoice->company);
    }

    public function issuesEcfFor(?CompanyInterface $company): bool
    {
        return $company instanceof Companies && $this->providers->issuesEcf($company);
    }

    public function ecfTypeFor(Invoice $invoice): ?EcfTypeEnum
    {
        return $this->issuesEcf($invoice) ? $this->types->resolve($invoice) : null;
    }

    /**
     * Approval is for agents, never for people (plan §2). Here rather than in a tool so a workflow, an
     * inbox job or a future tool cannot issue around it.
     */
    public function assertMayIssue(Invoice $invoice): void
    {
        if (ApprovalOriginService::current() !== ApprovalOriginEnum::AGENT || $invoice->isApproved()) {
            return;
        }

        throw new ApprovalRequiredException(
            "Invoice {$invoice->getId()} is an e-CF and an agent cannot issue it without a person's approval. "
            . 'Submit it for approval instead; it is issued when the approver signs.'
        );
    }

    /**
     * An E31 names a DGII-registered buyer. A number of the right shape that DGII does not know (a foreign id saved as
     * an RNC, a typo) is refused before it costs an e-NCF; a failed lookup or no provider to ask lets it through, so
     * an outage never stops issuing.
     */
    public function assertBuyerIsRegistered(Invoice $invoice): void
    {
        if (! $this->issuesEcf($invoice) || $this->types->resolve($invoice) !== EcfTypeEnum::CREDITO_FISCAL) {
            return;
        }

        $taxId = (string) BuyerTaxId::of($invoice);
        $lookup = new TaxpayerLookupService()->lookup($invoice->app, $invoice->company, $taxId);

        if ($lookup->status === TaxpayerLookup::NOT_REGISTERED) {
            throw new ValidationException(
                "DGII has no taxpayer registered under {$taxId}, so invoice {$invoice->getId()} was not issued. "
                . 'If the customer is foreign, set its tax id type (for example us_ein) and it goes out as E32; '
                . 'otherwise correct the number.'
            );
        }
    }

    /**
     * A note created and issued in one call skips the approval, so an agent on an e-CF company must draft it
     * and submit it instead.
     */
    public function assertAgentMayIssueNoteDirectly(CompanyInterface $company, string $noteLabel): void
    {
        if (! $this->issuesEcfFor($company) || ApprovalOriginService::current() !== ApprovalOriginEnum::AGENT) {
            return;
        }

        throw new ApprovalRequiredException(
            "This company issues e-CF, so an agent cannot issue a {$noteLabel} directly. Create it with draft() "
            . 'and submit it for approval; it is issued when a person approves it.'
        );
    }

    /**
     * Inside the issue transaction, after the billable snapshot is frozen: a rollback removes the number and
     * the fiscal row with it.
     */
    public function assignNumber(Invoice $invoice): void
    {
        $parent = $invoice->document_type->isNote() ? $invoice->parentInvoice : null;
        $referenced = $parent !== null ? EcfCompliance::current($parent) : null;

        if ($referenced !== null) {
            $this->assertNoteModifiesAcceptedEcf($parent, $referenced);
        }

        EcfExchangeRate::freeze($invoice, $parent);

        $fiscal = $this->allocateEcf(
            $invoice,
            $this->types->resolve($invoice),
            reference: $this->builder->reference($invoice),
            referencedDocument: $referenced,
        );

        $previousNumber = trim((string) $invoice->invoice_number);
        if ($previousNumber !== '' && $previousNumber !== $fiscal->number) {
            $invoice->metadata = [...($invoice->metadata ?? []), 'reference_number' => $previousNumber];
        }

        $invoice->invoice_number = $fiscal->number;
    }

    /**
     * Checked when the note is drafted, not when a person approves it: an approval that then fails for want of an
     * E33/E34 range leaves a draft nobody can issue and an approver who thinks it went out.
     */
    public function assertNoteNumbersAvailable(Invoice $parent, bool $isDebit): void
    {
        if (EcfCompliance::current($parent) === null) {
            return;
        }

        $type = $isDebit ? EcfTypeEnum::NOTA_DEBITO : EcfTypeEnum::NOTA_CREDITO;
        $sequence = new DocumentNumberAllocatorService()->find(
            (int) $parent->apps_id,
            (int) $parent->companies_id,
            DocumentTypeEnum::forEcf($type),
        );

        if ($sequence !== null && $sequence->isUsableRange()) {
            return;
        }

        throw new ValidationException(
            "This company has no usable {$type->prefix()} range from DGII, so the {$type->label()} could not get a number "
            . "and nothing was drafted. Load the {$type->prefix()} range DGII authorized (configure_ecf_range) first."
        );
    }

    /**
     * DGII accepts a note (E33/E34) only against an e-CF it accepted; a note on anything else is rejected and
     * burns a number. A number held elsewhere never reached DGII under this invoice, so it is voided instead.
     */
    private function assertNoteModifiesAcceptedEcf(Invoice $parent, FiscalDocument $referenced): void
    {
        if ($referenced->status->isAccepted()) {
            return;
        }

        throw new ValidationException(
            $referenced->isHeldElsewhere()
                ? "Invoice {$parent->invoice_number}'s e-NCF belongs to another document at the provider and never reached "
                    . 'DGII under this invoice, so no note can modify it. Void that invoice instead.'
                : "Invoice {$parent->invoice_number} is an e-CF DGII has not accepted ({$referenced->status->value}); a note "
                    . 'can only modify an accepted e-CF. Wait for DGII\'s answer (ecf_status) first.'
        );
    }

    /**
     * The e-CF the company issues on a bill or expense it already booked (E41/E43/E47). The bill keeps the
     * vendor's own number; the e-NCF lives only on the fiscal row. Nothing posts: the purchase is already on
     * the books.
     */
    public function assignPurchaseNumber(Bill|Expense $document, EcfTypeEnum $type): FiscalDocument
    {
        EcfExchangeRate::freeze($document);

        return $this->allocateEcf($document, $type);
    }

    /**
     * Approval for a purchase e-CF is its own type, so a bill that was approved for payment is not thereby
     * approved to be reported to DGII.
     */
    public function assertMayIssuePurchase(Bill|Expense $document): void
    {
        if (ApprovalOriginService::current() !== ApprovalOriginEnum::AGENT) {
            return;
        }

        $approved = $document->approvalRequests()
            ->where('approval_type', self::PURCHASE_APPROVAL_TYPE)
            ->where('status', ApprovalStatusEnum::APPROVED)
            ->exists();

        if (! $approved) {
            throw new ApprovalRequiredException(
                'An agent cannot issue an e-CF on a ' . class_basename($document) . " ({$document->getId()}) without a person's approval. "
                . 'Submit it for approval instead; it is issued when the approver signs.'
            );
        }
    }

    /**
     * Under the entity lock: a document that already has a usable current row gets that row back, so two
     * concurrent issues of one document take one number. Otherwise validate the numberless document, take the
     * next e-NCF of the type's range and write the fiscal row.
     */
    private function allocateEcf(
        Invoice|Bill|Expense $document,
        EcfTypeEnum $type,
        ?EcfReference $reference = null,
        ?FiscalDocument $referencedDocument = null,
    ): FiscalDocument {
        return DB::connection('accounting')->transaction(function () use ($document, $type, $reference, $referencedDocument): FiscalDocument {
            CreateFiscalDocumentAction::lockEntity($document);

            $current = EcfCompliance::current($document);
            if ($current !== null && ! $current->isBurned()) {
                return $current;
            }

            DrFiscalRegime::requireIssuerTaxId($document->company);

            $documentType = DocumentTypeEnum::forEcf($type);
            $sequence = $this->allocator->find($document->apps_id, $document->companies_id, $documentType)
                ?? throw new DocumentSequenceUnavailableException(
                    "No authorized {$type->prefix()} range is configured for this company. Configure one with kanvas-scribe:ecf-sequence before issuing."
                );
            $expiresAt = $sequence->expires_at?->toDateString();

            $built = $this->builder->buildFor(
                $document,
                type: $type,
                encf: '',
                expiresAt: $expiresAt ?? '',
            );
            $this->validator->assertValid($built);

            $encf = $this->allocator->allocate($document->apps_id, $document->companies_id, $documentType);
            $sequence->refresh();

            $fiscal = new CreateFiscalDocumentAction(
                entity: $document,
                direction: FiscalDirectionEnum::ISSUED,
                attributes: [
                    ...DrFiscalRegime::issued(
                        document: $built,
                        encf: $encf,
                        company: $document->company,
                        provider: $this->providers->providerKey($document->company),
                        environment: EcfEnvironmentEnum::forCompany($document->company)->value,
                        regimeData: [
                            ...EcfCompliance::draftSettings($document),
                            'expires_at' => $expiresAt,
                            'issue_date' => $built->issueDate,
                            'references' => $reference?->toArray(),
                        ],
                    ),
                    'fiscal_number_range_id' => $sequence->fiscal_number_range_id,
                ],
                reference: $reference,
                referencedDocument: $referencedDocument,
            )->execute();

            if ($sequence->isExhausted() && $sequence->fiscal_number_range_id !== null) {
                FiscalNumberRange::query()->whereKey($sequence->fiscal_number_range_id)->update(['status' => FiscalRangeStatusEnum::EXHAUSTED->value]);
            }

            $this->noteSequenceAttention($document, $sequence);

            return $fiscal;
        });
    }

    /**
     * afterCommit: the job must never read a number or a JE the issue transaction could still roll back.
     */
    public function dispatchSubmission(Invoice|Bill|Expense $document): void
    {
        $fiscal = EcfCompliance::current($document);

        if ($fiscal !== null && $fiscal->status === FiscalDocumentStatusEnum::DRAFT) {
            SubmitEcfJob::dispatch($document->app, $fiscal)->afterCommit();
        }
    }

    /**
     * Read by the AR/AP digest, which tells the admin to request a new range from DGII.
     */
    private function noteSequenceAttention(Invoice|Bill|Expense $invoice, DocumentSequence $sequence): void
    {
        $reasons = $sequence->attentionReasons();

        if ($reasons === []) {
            return;
        }

        try {
            $invoice->emitLedgerEvent(
                eventType: 'scribe.ecf_sequence.attention',
                payload: [
                    'document_sequence_id' => $sequence->getKey(),
                    'prefix' => $sequence->prefix,
                    'reasons' => $reasons,
                    'remaining' => $sequence->remaining(),
                    'used_ratio' => $sequence->usedRatio() !== null ? round($sequence->usedRatio(), 4) : null,
                    'range_end' => $sequence->range_end !== null ? $sequence->format($sequence->range_end) : null,
                    'expires_at' => $sequence->expires_at?->toDateString(),
                ],
            );
        } catch (Throwable $e) {
            report($e);
        }
    }
}
