<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\SubmitInvoiceForApprovalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;

/** Creates a one-line AR invoice and optionally issues it immediately in Kanvas. */
#[AgentTool(name: 'Create AR Invoice', category: 'accounting')]
class CreateArInvoiceTool extends Tool
{
    use HasKanvasContext;
    use ReadsApprovalSourceFields;
    use ResolvesCustomerForTool;
    use StoresApprovalSourceFields;
    use TrackByInputs;

    protected string $name = 'create_ar_invoice';

    protected ?string $description = 'Creates a one-line AR invoice directly in the Kanvas ledger. By default it issues '
        . 'the invoice immediately; this bypasses the normal human approval gate, so only do this when the user '
        . 'explicitly asks, never on a whim. Set issue_immediately to false to submit a draft invoice for human '
        . 'approval. The invoice stays open; use apply_ar_payment separately to record a payment against it. '
        . 'External synchronization is handled by configured workflows.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The invoice amount, e.g. 1.00.',
                required: true,
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
                description: 'Whether to issue this invoice immediately in the Kanvas ledger. Defaults to true. '
                    . 'Set to false to submit a draft invoice for human approval. External synchronization is '
                    . 'handled separately by configured workflows.',
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
        float $amount,
        string $memo,
        ?string $currency = null,
        ?bool $issue_immediately = null,
        ?string $source_email_message_id = null,
        ?int $source_attachment_filesystem_id = null,
    ): array {
        $issue_immediately ??= true;
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

        $invoice = new CreateInvoiceAction(
            new InvoiceData(
                app: $app,
                company: $company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: $memo,
                        quantity: 1.0,
                        unit_price_native: $amount,
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
                issued_date: Carbon::today(),
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        $this->storeApprovalSourceFields($invoice, $source_email_message_id, $source_attachment_filesystem_id);

        if (! $issue_immediately) {
            new SubmitInvoiceForApprovalAction($invoice, $actingUser)->execute();

            $approverEmails = ResolveApproverEmailAction::resolveForOrganization($customer);
            $sourceFields = $this->sourceFields($invoice);

            NotifyApproverAction::notifyAll(
                approverEmails: $approverEmails,
                app: $app,
                text: "You have an AR invoice pending approval:\nCustomer: {$customerDisplayName}\nAmount: "
                    . "{$currency} {$amount}\nMemo: {$memo}\nInvoice ID (Kanvas): {$invoice->getId()}\n\nReply "
                    . "\"approve invoice {$invoice->getId()}\" to issue it.",
                attachmentUrl: $sourceFields['source_attachment_url'],
                attachmentFilename: $sourceFields['source_attachment_filename'],
            );

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
                        . 'configured — nobody can approve it and no notification was sent. Write approved_by_flag '
                        . 'into the sheet\'s Approved By column so this is visible there too, and tell the user to '
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

        $invoice = new IssueInvoiceAction($invoice, $customer, $actingUser)->execute();
        $invoice->refresh();

        return [
            'created' => true,
            'invoice_id' => $invoice->getId(),
            'document_status' => $invoice->document_status->value,
            'customer' => $customerDisplayName,
            'amount' => $amount,
            'currency' => $currency,
            'memo' => $memo,
            'remaining_balance' => (float) $invoice->balance_due_native,
            'next' => 'Invoice issued in Kanvas. Any external synchronization is handled by configured workflow '
                . 'activities; use apply_ar_payment with this invoice_id to record a payment.',
        ];
    }
}
