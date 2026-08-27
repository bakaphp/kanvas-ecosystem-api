<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Creates a one-line AP bill and, by default, auto-approves it and pushes it to Acumatica in one step. */
#[AgentTool(name: 'Create AP Bill', category: 'accounting')]
class CreateApBillTool extends Tool
{
    use HasKanvasContext;
    use StoresApprovalSourceFields;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ap_bill',
            description: 'Creates a one-line AP bill. By default also auto-approves it and pushes it to Acumatica '
                . 'in one step, returning the Acumatica bill reference — bypassing the normal human approval gate, '
                . 'so only do this when the user explicitly asks to create a bill this way, never on a whim. Set '
                . 'push_to_acumatica to false to just create the bill and submit it for approval (status: '
                . 'pending_approval) without touching Acumatica — this is the default for the standard automatic '
                . 'invoice-processing flow, where a human approves it later and the push happens separately.',
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
                description: 'The bill amount, e.g. 1.00.',
                required: true,
            ),
            new ToolProperty(
                name: 'gl_account_number',
                type: PropertyType::STRING,
                description: 'The GL expense account number to code the line to, e.g. "71610".',
                required: true,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Description / memo for the bill and its single line.',
                required: true,
            ),
            new ToolProperty(
                name: 'vendor_name',
                type: PropertyType::STRING,
                description: 'Vendor name to match (substring). Always required — never guess or pick an '
                    . 'arbitrary vendor; ask the user which one if it is not clear from context.',
                required: true,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The vendor\'s own invoice number (not a Kanvas-generated one) — becomes the '
                    . 'Vendor Ref on the Acumatica document. Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'subaccount',
                type: PropertyType::STRING,
                description: 'Acumatica subaccount code for the line, e.g. "BB-0G-M1", when the vendor or '
                    . 'project requires a specific one. Optional — omit to let Acumatica derive a default from '
                    . 'the GL account\'s history, which is not always correct for every vendor on that account.',
                required: false,
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
                description: 'Whether to auto-approve and push this bill to Acumatica immediately. Defaults to '
                    . 'true. Set to false to just create the bill and submit it for approval (status: '
                    . 'pending_approval) and stop there — used by the standard automatic invoice-processing '
                    . 'flow, where a human approves it later and the Acumatica push happens as a separate step.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_email_message_id',
                type: PropertyType::STRING,
                description: 'The Gmail message_id of the invoice email this bill was created from, when '
                    . 'created as part of the automatic invoice-email flow. Kept so a later approval (often in '
                    . 'a separate Slack conversation) can reply in that same email thread with evidence.',
                required: false,
            ),
            new ToolProperty(
                name: 'source_attachment_url',
                type: PropertyType::STRING,
                description: 'The Kanvas-hosted URL of the invoice PDF (from download_attachment), when created '
                    . 'as part of the automatic invoice-email flow. Kept so it can be attached to the bill once '
                    . 'it is actually pushed to Acumatica, at approval time.',
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
        string $vendor_name,
        float $amount,
        string $gl_account_number,
        string $memo,
        string $invoice_number,
        ?string $subaccount = null,
        ?string $currency = null,
        ?bool $push_to_acumatica = null,
        ?string $source_email_message_id = null,
        ?string $source_attachment_url = null,
        ?string $source_attachment_filename = null,
    ): array {
        $push_to_acumatica ??= true;
        $app = $this->app;
        $company = $this->company;

        if (trim($vendor_name) === '') {
            return [
                'created' => false,
                'reason' => 'vendor_name_required',
                'message' => 'A vendor_name is required — never pick an arbitrary vendor.',
            ];
        }

        if (trim($invoice_number) === '') {
            return [
                'created' => false,
                'reason' => 'invoice_number_required',
                'message' => 'An invoice_number (the vendor\'s own invoice reference) is required.',
            ];
        }

        $match = OrganizationVendorMatcherService::match($app, $company, $vendor_name);

        if (! $match->isMatched()) {
            return [
                'created' => false,
                'reason' => $match->candidates !== [] ? 'vendor_ambiguous' : 'vendor_not_found',
                'message' => $match->candidates !== []
                    ? "\"{$vendor_name}\" could match more than one vendor: "
                        . implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates))
                        . '. Call find_vendor to see Acumatica codes and confirm the right one with the user.'
                    : "No vendor organization matching \"{$vendor_name}\" for this app/company.",
            ];
        }

        /** @var Organization $vendor */
        $vendor = $match->organization;

        $account = Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_number', $gl_account_number)
            ->where('is_active', true)
            ->first();

        if ($account === null) {
            return [
                'created' => false,
                'reason' => 'account_not_found',
                'message' => "No active GL account with number \"{$gl_account_number}\" for this app/company.",
            ];
        }

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;
        $subaccountId = $this->resolveSubaccountId($subaccount, $app, $company);

        $bill = new CreateBillAction(
            new BillData(
                app: $app,
                company: $company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: $memo,
                        quantity: 1.0,
                        unit_price_native: $amount,
                        expense_account_id: $account->getId(),
                        subaccount_id: $subaccountId,
                    ),
                ]),
                currency: $currency,
                fx_rate_to_base: 1.0,
                bill_number: $invoice_number,
                bill_date: Carbon::today(),
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        $bill = new SubmitBillForApprovalAction($bill, $actingUser)->execute();

        $this->storeApprovalSourceFields(
            $bill,
            $source_email_message_id,
            $source_attachment_url,
            $source_attachment_filename,
        );

        if (! $push_to_acumatica) {
            $approverEmail = trim((string) $vendor->get(OrganizationApproverCustomFieldEnum::APPROVER_EMAIL->value, ''));

            new NotifyApproverAction(
                app: $app,
                text: "You have an AP bill pending approval:\nVendor: {$vendor->name}\nAmount: {$currency} "
                    . "{$amount}\nGL: {$gl_account_number}"
                    . ($subaccount !== null && trim($subaccount) !== '' ? " / Subaccount: {$subaccount}" : '')
                    . "\nMemo: {$memo}\nBill ID (Kanvas): {$bill->getId()}\n\nReply \"approve bill "
                    . "{$bill->getId()}\" to approve it and push it to Acumatica.",
                approverEmail: $approverEmail !== '' ? $approverEmail : null,
                attachmentUrl: $source_attachment_url,
                attachmentFilename: $source_attachment_filename,
            )->execute();

            return [
                'created' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'bill_number' => $bill->bill_number,
                'document_status' => $bill->document_status->value,
                'vendor' => $vendor->name,
                'amount' => $amount,
                'currency' => $currency,
                'gl_account' => $gl_account_number,
                'subaccount' => $subaccount,
                'memo' => $memo,
                'next' => $approverEmail !== ''
                    ? 'Bill created and submitted for approval in Kanvas (status: pending_approval). Not pushed '
                        . 'to Acumatica — that happens separately once a human approves it.'
                    : 'Bill created and submitted for approval, but vendor "' . $vendor->name . '" has no '
                        . 'approver email configured — nobody can approve it and no notification was sent. Tell '
                        . 'the user to have an admin set that vendor\'s approver email.',
            ];
        }

        /** @var Organization $approvalVendor */
        $approvalVendor = Organization::query()->where('id', $bill->vendor_organization_id)->first();

        $bill = new ApproveBillAction($bill, $approvalVendor, $actingUser)->execute();

        try {
            $reference = new PushBillToAcumaticaAction($bill)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'created' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'bill_number' => $bill->bill_number,
                'document_status' => $bill->document_status->value,
                'reason' => 'push_failed',
                'message' => 'Bill was created and approved in Kanvas (status: received) but the push to '
                    . 'Acumatica failed: ' . $e->getMessage() . '. It needs manual attention — it will not '
                    . 'auto-retry.',
            ];
        }

        return [
            'created' => true,
            'pushed' => true,
            'bill_id' => $bill->getId(),
            'bill_number' => $bill->bill_number,
            'document_status' => $bill->document_status->value,
            'vendor' => $vendor->name,
            'amount' => $amount,
            'currency' => $currency,
            'gl_account' => $gl_account_number,
            'subaccount' => $subaccount,
            'memo' => $memo,
            'bill_ref' => $reference,
            'acumatica_bill_id' => (string) $bill->get(AcumaticaCustomFieldEnum::BILL_ID->value, ''),
            'next' => 'Pushed to Acumatica. bill_ref is the ERP reference.',
        ];
    }

    private function resolveSubaccountId(?string $subaccount, Apps $app, Companies $company): ?int
    {
        if ($subaccount === null || trim($subaccount) === '') {
            return null;
        }

        $subaccountModel = Subaccount::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'sub_code' => trim($subaccount),
            ],
            ['source' => 'kanvas'],
        );

        return (int) $subaccountModel->getKey();
    }
}
