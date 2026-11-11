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
use Kanvas\Scribe\ElectronicInvoicing\Actions\CreateFiscalDocumentAction;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalDocumentTypeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfEnvironmentEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\SubmitEcfJob;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\FiscalRegimes;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfExchangeRate;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * The electronic-invoicing half of issuing a document, for a company with a provider set, in any country: every
 * country-specific decision (document type, checks, numbering, payload, validation, the fiscal row) is its
 * regime's. A company without a provider never reaches it and keeps its plain numbering.
 *
 * Order matters and is the whole point: refuse the agent without approval, check, validate the numberless
 * document, and only then take the number, so neither a missing approval nor a document the authority would
 * reject ever spends one.
 */
class EcfIssuanceService
{
    public const string PURCHASE_APPROVAL_TYPE = 'approve_purchase_ecf';

    public function __construct(
        protected readonly EcfProviderResolver $providers = new EcfProviderResolver(),
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

    public function ecfTypeFor(Invoice $invoice): ?FiscalDocumentTypeInterface
    {
        return $this->issuesEcf($invoice) ? FiscalRegimes::requireForCompany($invoice->company)->salesDocumentType($invoice) : null;
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
     * The regime's checks that may need the network (a buyer's registration at the authority), so they run before
     * the issue transaction and before a person is asked to approve.
     */
    public function assertReadyToIssue(Invoice|Bill|Expense $document, ?FiscalDocumentTypeInterface $type = null): void
    {
        if (! $this->issuesEcfFor($document->company)) {
            return;
        }

        $regime = FiscalRegimes::requireForCompany($document->company);
        $type ??= $document instanceof Invoice ? $regime->salesDocumentType($document) : null;

        if ($type !== null) {
            $regime->assertMayIssue($document, $type);
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
            $this->assertNoteModifiesAcceptedDocument($parent, $referenced);
        }

        EcfExchangeRate::freeze($invoice, $parent);

        $fiscal = $this->allocate(
            $invoice,
            FiscalRegimes::requireForCompany($invoice->company)->salesDocumentType($invoice),
            referencedDocument: $referenced,
        );

        $previousNumber = trim((string) $invoice->invoice_number);
        if ($previousNumber !== '' && $previousNumber !== $fiscal->number) {
            $invoice->metadata = [...($invoice->metadata ?? []), 'reference_number' => $previousNumber];
        }

        $invoice->invoice_number = $fiscal->number;
    }

    /**
     * Checked when the note is drafted, not when a person approves it: an approval that then fails for want of a
     * number leaves a draft nobody can issue and an approver who thinks it went out.
     */
    public function assertNoteNumbersAvailable(Invoice $parent, bool $isDebit): void
    {
        if (EcfCompliance::current($parent) === null) {
            return;
        }

        $regime = FiscalRegimes::requireForCompany($parent->company);
        $regime->numbering()->assertAvailable($parent, $regime->noteDocumentType($isDebit));
    }

    /**
     * An authority accepts a note only against a document it accepted; a note on anything else is rejected and
     * spends a number. A number held elsewhere never reached the authority under this invoice, so it is voided instead.
     */
    private function assertNoteModifiesAcceptedDocument(Invoice $parent, FiscalDocument $referenced): void
    {
        if ($referenced->status->isAccepted()) {
            return;
        }

        $authority = $referenced->regime()->authority();

        throw new ValidationException(
            $referenced->isHeldElsewhere()
                ? "Invoice {$parent->invoice_number}'s fiscal number belongs to another document at the provider and never reached "
                    . "{$authority} under this invoice, so no note can modify it. Void that invoice instead."
                : "Invoice {$parent->invoice_number} is an electronic invoice {$authority} has not accepted ({$referenced->status->value}); "
                    . "a note can only modify an accepted one. Wait for {$authority}'s answer (ecf_status) first."
        );
    }

    /**
     * The document the company issues itself on a bill or expense it already booked (DR E41/E43/E47). The bill keeps
     * the vendor's own number; the fiscal number lives only on the fiscal row. Nothing posts: the purchase is already
     * on the books.
     */
    public function assignPurchaseNumber(Bill|Expense $document, FiscalDocumentTypeInterface $type): FiscalDocument
    {
        EcfExchangeRate::freeze($document);

        return $this->allocate($document, $type);
    }

    /**
     * Approval for a purchase e-CF is its own type, so a bill that was approved for payment is not thereby
     * approved to be reported to the tax authority.
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
     * next number and write the fiscal row.
     */
    private function allocate(
        Invoice|Bill|Expense $document,
        FiscalDocumentTypeInterface $type,
        ?FiscalDocument $referencedDocument = null,
    ): FiscalDocument {
        return DB::connection('accounting')->transaction(function () use ($document, $type, $referencedDocument): FiscalDocument {
            CreateFiscalDocumentAction::lockEntity($document);

            $current = EcfCompliance::current($document);
            if ($current !== null && ! $current->isBurned()) {
                return $current;
            }

            $regime = FiscalRegimes::requireForCompany($document->company);
            $numbering = $regime->numbering();
            $slot = $numbering->reserve($document, $type);

            $regime->assertValid($regime->buildPayload(
                document: $document,
                type: $type,
                number: '',
                expiresAt: $slot->expiresAt,
            ));

            $slot = $numbering->take($document, $slot);
            $payload = $regime->buildPayload(
                document: $document,
                type: $type,
                number: $slot->number,
                expiresAt: $slot->expiresAt,
            );
            $reference = $payload->reference();

            return new CreateFiscalDocumentAction(
                entity: $document,
                direction: FiscalDirectionEnum::ISSUED,
                attributes: [
                    ...$regime->issuedAttributes(
                        payload: $payload,
                        company: $document->company,
                        provider: $this->providers->providerKey($document->company),
                        environment: EcfEnvironmentEnum::forCompany($document->company)->value,
                        regimeData: [
                            ...EcfCompliance::draftSettings($document),
                            'expires_at' => $slot->expiresAt,
                            'issue_date' => $payload->issueDate(),
                            'references' => $reference?->toArray(),
                        ],
                    ),
                    'fiscal_number_range_id' => $slot->fiscalNumberRangeId,
                ],
                reference: $reference,
                referencedDocument: $referencedDocument,
            )->execute();
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
}
