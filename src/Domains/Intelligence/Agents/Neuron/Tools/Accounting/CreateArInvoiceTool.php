<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Money;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Exceptions\ApprovalRequiredException;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/**
 * Creates an AR invoice from lines or a single amount and submits it for approval (or, on explicit request,
 * issues it). Runs as origin AGENT so IssueInvoiceAction and the approval policy can tell an agent from a person.
 */
#[AgentTool(name: 'Create AR Invoice', category: 'accounting')]
class CreateArInvoiceTool extends Tool
{
    use HasKanvasContext;
    use ReadsApprovalSourceFields;
    use ResolvesCustomerForTool;
    use StoresApprovalSourceFields;
    use TrackByInputs;

    protected string $name = 'create_ar_invoice';

    protected ?string $description = 'Creates an AR invoice in the Kanvas ledger: give lines (one per product or service, with '
        . 'its ITBIS/tax rate), or a single amount for a one-line invoice. By default it creates a draft and '
        . 'submits it for human approval; it is issued when a person approves it. Set issue_immediately to true '
        . 'only when the user explicitly asks to issue now — for a company that issues electronic invoices (e-CF) '
        . 'it is still submitted for approval, because an agent never issues an e-CF on its own. The invoice '
        . 'stays open; use apply_ar_payment separately to record a payment against it. External synchronization '
        . 'is handled by configured workflows.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ArrayProperty(
                name: 'lines',
                description: 'One entry per product or service. Prefer this over amount: each line carries its own '
                    . 'tax. On a company that issues e-CF every line must give tax_rate (18 for ITBIS, 0 when exempt).',
                required: false,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'One invoice line.',
                    properties: [
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'What is billed.',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'Quantity. Defaults to 1.',
                            required: false
                        ),
                        new ToolProperty(
                            name: 'unit_price',
                            type: PropertyType::NUMBER,
                            description: 'Unit price before tax.',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'tax_rate',
                            type: PropertyType::NUMBER,
                            description: 'Tax rate as a percent (18 for ITBIS, 0 when exempt). The tax is computed from it.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'Total for a one-line invoice with no tax, e.g. 1.00. Ignored when lines are given; not '
                    . 'accepted on a company that issues e-CF (give lines with tax_rate instead).',
                required: false,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Description / memo for the invoice and its single line.',
                required: true,
            ),
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Customer name to match (substring). Always required — never guess or pick an '
                    . 'arbitrary customer; ask the user which one if it is not clear from context.',
                required: true,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'issue_immediately',
                type: PropertyType::BOOLEAN,
                description: 'Whether to try to issue this invoice immediately instead of submitting it for '
                    . 'approval. Defaults to false. Even when true, an e-CF company still requires a person\'s '
                    . 'approval and the result says so.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_email_message_id',
                type: PropertyType::STRING,
                description: 'The Gmail message_id of the invoice email this invoice was created from, when '
                    . 'created as part of the automatic invoice-email flow. Kept so a later approval (often in '
                    . 'a separate Slack conversation) can reply in that same email thread with evidence.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_attachment_filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of the invoice PDF (from download_attachment), when created as '
                    . 'part of the automatic invoice-email flow. Attaches the PDF to the invoice via Kanvas '
                    . 'Filesystem so it can be forwarded to the approver and seen later.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $customer_name,
        string $memo,
        ?float $amount = null,
        ?array $lines = null,
        ?string $currency = null,
        ?bool $issue_immediately = null,
        ?string $source_email_message_id = null,
        ?int $source_attachment_filesystem_id = null,
    ): array {
        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): array => $this->createInvoice(
                $customer_name,
                $amount,
                $lines,
                $memo,
                $currency,
                $issue_immediately ?? false,
                $source_email_message_id,
                $source_attachment_filesystem_id,
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function createInvoice(
        string $customer_name,
        ?float $amount,
        ?array $lines,
        string $memo,
        ?string $currency,
        bool $issue_immediately,
        ?string $source_email_message_id,
        ?int $source_attachment_filesystem_id,
    ): array {
        $app = $this->app;
        $company = $this->company;

        if (trim($customer_name) === '') {
            return [
                'created' => false,
                'reason' => 'customer_name_required',
                'message' => 'A customer_name is required — never pick an arbitrary customer.',
            ];
        }

        $customer = $this->resolveCustomerOrError(
            $customer_name,
            'Call find_customer to confirm the right customer with the user.',
        );

        if (is_array($customer)) {
            return ['created' => false, ...$customer];
        }

        $customerDisplayName = trim((string) $customer->get(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, '')) ?: $customer->name;

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;

        $lineData = $this->lineData(
            $lines,
            $amount,
            $memo,
            new EcfIssuanceService()->issuesEcfFor($company)
        );
        if (is_string($lineData)) {
            return ['created' => false, 'reason' => 'invalid_lines', 'message' => $lineData];
        }

        $invoice = new CreateInvoiceAction(
            new InvoiceData(
                app: $app,
                company: $company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, $lineData),
                currency: $currency,
                fx_rate_to_base: 1.0,
                issued_date: Carbon::today(),
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        $this->storeApprovalSourceFields($invoice, $source_email_message_id, $source_attachment_filesystem_id);
        $amount = (float) $invoice->total_native;

        $approvalReason = null;

        if ($issue_immediately) {
            try {
                $invoice = new IssueInvoiceAction($invoice, $customer, $actingUser)->execute();
                $invoice->refresh();

                return [
                    'created' => true,
                    'invoice_id' => $invoice->getId(),
                    'invoice_number' => $invoice->invoice_number,
                    'document_status' => $invoice->document_status->value,
                    'customer' => $customerDisplayName,
                    'amount' => $amount,
                    'currency' => $currency,
                    'memo' => $memo,
                    'remaining_balance' => (float) $invoice->balance_due_native,
                    'next' => 'Invoice issued in Kanvas. Any external synchronization is handled by configured workflow '
                        . 'activities; use apply_ar_payment with this invoice_id to record a payment.',
                ];
            } catch (ApprovalRequiredException $e) {
                $approvalReason = $e->getMessage();
            }
        }

        if (new EcfIssuanceService()->issuesEcf($invoice)) {
            return $this->submitEcfInvoice(
                $invoice,
                $customerDisplayName,
                $amount,
                $currency,
                $memo,
                $approvalReason
            );
        }

        new SubmitInvoiceForApprovalAction($invoice, $actingUser)->execute();

        $approverEmails = ResolveApproverEmailAction::resolveForOrganization($customer);
        $sourceFields = $this->sourceFields($invoice);

        // The approval request notified the approvers (Slack DM from the AR notifier, email otherwise).
        $result = [
            'created' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'document_status' => $invoice->document_status->value,
            'customer' => $customerDisplayName,
            'amount' => $amount,
            'currency' => $currency,
            'memo' => $memo,
            'remaining_balance' => (float) $invoice->balance_due_native,
            'approved_by_flag' => $approverEmails !== [] ? '' : 'NOT IN APPROVER LIST',
            'next' => $approverEmails !== []
                ? 'Invoice created in Kanvas (status: draft) and submitted for human approval.'
                : 'Invoice created in Kanvas, but customer "' . $customerDisplayName . '" has no approver '
                    . 'configured — nobody can approve it and no notification was sent. Tell the user to '
                    . 'have an admin set that customer\'s approver.',
        ];

        // Same warning create_ap_bill returns: the approver was DM'd without the PDF, and the model
        // is the only one who can notice and follow up with resend_invoice_attachment.
        if ($source_email_message_id !== null
            && trim($source_email_message_id) !== ''
            && $sourceFields['source_attachment_url'] === null
        ) {
            $result['attachment_warning'] = 'This invoice has a source email but no '
                . 'source_attachment_filesystem_id — the approver was notified without the invoice PDF '
                . 'attached.';
        }

        return $result;
    }

    /**
     * e-CF: the policy decides who signs, and its digest notifies them — not the customer-approver Slack DM.
     * A company that turned invoice approval off gets the invoice issued straight away.
     *
     * @return array<string, mixed>
     */
    private function submitEcfInvoice(
        Invoice $invoice,
        string $customerDisplayName,
        float $amount,
        string $currency,
        string $memo,
        ?string $approvalReason,
    ): array {
        try {
            $invoice = new SubmitInvoiceForApprovalAction($invoice, $this->user)->execute();
        } catch (Throwable $e) {
            return [
                'created' => true,
                'invoice_id' => $invoice->getId(),
                'document_status' => $invoice->document_status->value,
                'submitted' => false,
                'message' => 'The draft invoice was created but could not be submitted for approval: ' . $e->getMessage(),
            ];
        }

        $issued = $invoice->document_status !== InvoiceDocumentStatusEnum::DRAFT;

        return [
            'created' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_number' => $invoice->invoice_number,
            'document_status' => $invoice->document_status->value,
            'customer' => $customerDisplayName,
            'amount' => $amount,
            'currency' => $currency,
            'memo' => $memo,
            'awaiting_approval' => ! $issued,
            'note' => $approvalReason,
            'next' => $issued
                ? 'This company does not require approval for agent invoices, so it was issued and sent to the e-CF '
                    . 'provider. The DGII result arrives asynchronously.'
                : 'Draft e-CF invoice submitted for a person\'s approval. No e-NCF is assigned until it is approved; '
                    . 'use check_approval_status to follow it.',
        ];
    }

    /**
     * A one-line amount carries no tax, so on an e-CF company it would reach DGII as exempt: every line there has to
     * state its rate, 0 included, so ITBIS is never dropped by omission.
     *
     * @param array<int, array<string, mixed>>|null $lines
     * @return list<InvoiceLineData>|string the lines, or why they were refused
     */
    private function lineData(
        ?array $lines,
        ?float $amount,
        string $memo,
        bool $issuesEcf
    ): array|string {
        if ($lines === null || $lines === []) {
            if ($issuesEcf) {
                return 'This company issues e-CF: give lines, each with its tax_rate (18 for ITBIS, 0 when exempt). '
                    . 'A bare amount would reach DGII as exempt. Nothing was created.';
            }

            if ($amount === null || $amount <= 0) {
                return 'Give lines or a positive amount. Nothing was created.';
            }

            return [new InvoiceLineData(description: $memo, quantity: 1.0, unit_price_native: $amount)];
        }

        $data = [];
        foreach ($lines as $line) {
            $quantity = max(0.01, (float) ($line['quantity'] ?? 1));
            $unitPrice = (float) ($line['unit_price'] ?? 0);
            $rate = isset($line['tax_rate']) && is_numeric($line['tax_rate']) ? (float) $line['tax_rate'] : null;

            if ($unitPrice <= 0) {
                return 'Every line needs a positive unit_price. Nothing was created.';
            }

            if ($rate === null && $issuesEcf) {
                return 'This company issues e-CF: every line needs tax_rate (18 for ITBIS, 0 when exempt). Nothing was created.';
            }

            $data[] = new InvoiceLineData(
                description: Str::trimToNull((string) ($line['description'] ?? '')) ?? $memo,
                quantity: $quantity,
                unit_price_native: $unitPrice,
                tax_rate: $rate,
                tax_amount_native: $rate !== null ? Money::cents($quantity * $unitPrice * $rate / 100) : 0.0,
            );
        }

        return $data;
    }
}
