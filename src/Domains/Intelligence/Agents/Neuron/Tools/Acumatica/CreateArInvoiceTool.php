<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica\Traits\PushesInvoiceWithCreditHoldRetry;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Actions\RequestApprovalAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Creates a one-line AR invoice and, by default, issues it and pushes it to Acumatica — the AR mirror of CreateApBillTool. Stays open; use apply_ar_payment to record a payment against it separately. */
#[AgentTool(name: 'Create AR Invoice', category: 'accounting')]
class CreateArInvoiceTool extends Tool
{
    use HasKanvasContext;
    use PushesInvoiceWithCreditHoldRetry;
    use StoresApprovalSourceFields;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ar_invoice',
            description: 'Creates a one-line AR invoice for a customer. By default also issues it and pushes it '
                . 'to Acumatica in one step, returning the invoice ref — bypassing the normal human approval gate, '
                . 'so only do this when the user explicitly asks to create an invoice this way, never on a whim. '
                . 'The invoice stays open; use apply_ar_payment separately to record a payment against it. Set '
                . 'push_to_acumatica to false to just create the invoice (status: draft) and stop there — this is '
                . 'the default for the standard automatic invoice-processing flow, where a human issues/pushes it '
                . 'later as a separate step.',
        );
    }

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
                name: 'push_to_acumatica',
                type: PropertyType::BOOLEAN,
                description: 'Whether to issue and push this invoice to Acumatica immediately. Defaults to true. '
                    . 'Set to false to just create the invoice (status: draft) and stop there — used by the '
                    . 'standard automatic invoice-processing flow, where a human issues/pushes it later as a '
                    . 'separate step.',
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
                name: 'source_attachment_url',
                type: PropertyType::STRING,
                description: 'The Kanvas-hosted URL of the invoice PDF (from download_attachment), when created '
                    . 'as part of the automatic invoice-email flow. Kept so it can be attached to the invoice '
                    . 'once it is actually pushed to Acumatica, at approval time.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_attachment_filename',
                type: PropertyType::STRING,
                description: 'The file name for source_attachment_url (from download_attachment). Optional — '
                    . 'defaults to the URL\'s own file name when attached.',
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
        ?bool $push_to_acumatica = null,
        ?string $source_email_message_id = null,
        ?string $source_attachment_url = null,
        ?string $source_attachment_filename = null,
    ): array {
        $push_to_acumatica ??= true;
        $app = $this->app;
        $company = $this->company;

        if (trim($customer_name) === '') {
            return [
                'created' => false,
                'reason' => 'customer_name_required',
                'message' => 'A customer_name is required — never pick an arbitrary customer.',
            ];
        }

        $match = OrganizationVendorMatcherService::match($app, $company, $customer_name);

        if (! $match->isMatched()) {
            return [
                'created' => false,
                'reason' => $match->candidates !== [] ? 'customer_ambiguous' : 'customer_not_found',
                'message' => $match->candidates !== []
                    ? "\"{$customer_name}\" could match more than one customer: "
                        . implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates))
                        . '. Call find_customer to see Acumatica codes and confirm the right one with the user.'
                    : "No customer organization matching \"{$customer_name}\" for this app/company.",
            ];
        }

        /** @var Organization $customer */
        $customer = $match->organization;
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

        $this->storeApprovalSourceFields(
            $invoice,
            $source_email_message_id,
            $source_attachment_url,
            $source_attachment_filename,
        );

        if (! $push_to_acumatica) {
            new RequestApprovalAction(
                app: $app,
                company: $company,
                actionType: 'approve_invoice',
                targetType: 'invoice',
                targetId: $invoice->getId(),
                requestedByUser: $actingUser,
            )->execute();

            $approverEmail = trim((string) $customer->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, ''));

            new NotifyApproverAction(
                app: $app,
                text: "You have an AR invoice pending approval:\nCustomer: {$customerDisplayName}\nAmount: {$currency} "
                    . "{$amount}\nMemo: {$memo}\nInvoice ID (Kanvas): {$invoice->getId()}\n\nReply "
                    . "\"approve invoice {$invoice->getId()}\" to approve it and push it to Acumatica.",
                approverEmail: $approverEmail !== '' ? $approverEmail : null,
                attachmentUrl: $source_attachment_url,
                attachmentFilename: $source_attachment_filename,
            )->execute();

            return [
                'created' => true,
                'invoice_pushed' => false,
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->invoice_number,
                'document_status' => $invoice->document_status->value,
                'customer' => $customerDisplayName,
                'amount' => $amount,
                'currency' => $currency,
                'memo' => $memo,
                'approved_by_flag' => $approverEmail !== '' ? '' : 'NOT IN APPROVER LIST',
                'next' => $approverEmail !== ''
                    ? 'Invoice created in Kanvas (status: draft). Not issued or pushed to Acumatica — that '
                        . 'happens separately once a human approves it.'
                    : 'Invoice created in Kanvas, but customer "' . $customerDisplayName . '" has no approver email '
                        . 'configured — nobody can approve it and no notification was sent. Write approved_by_flag '
                        . 'into the sheet\'s Approved By column so this is visible there too, and tell the user to '
                        . 'have an admin set that customer\'s approver email.',
            ];
        }

        $invoice = new IssueInvoiceAction($invoice, $customer, $actingUser)->execute();

        try {
            $invoiceRef = $this->pushInvoiceWithCreditHoldRetry($invoice);
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'invoice_pushed' => false,
                'invoice_id' => $invoice->getId(),
                'invoice_number' => $invoice->invoice_number,
                'document_status' => $invoice->document_status->value,
                'reason' => 'invoice_push_failed',
                'message' => 'Invoice was created and issued in Kanvas (status: issued) but the push to '
                    . 'Acumatica failed: ' . $e->getMessage() . '. It needs manual attention — it will not '
                    . 'auto-retry.',
            ];
        }

        return [
            'created' => true,
            'invoice_pushed' => true,
            'invoice_id' => $invoice->getId(),
            'document_status' => $invoice->fresh()->document_status->value,
            'customer' => $customerDisplayName,
            'amount' => $amount,
            'currency' => $currency,
            'memo' => $memo,
            'invoice_ref' => $invoiceRef,
            'acumatica_invoice_id' => (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_ID->value, ''),
            'next' => 'Invoice pushed to Acumatica and left open. Use apply_ar_payment with this invoice_id to '
                . 'record a payment against it.',
        ];
    }
}
