<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\Actions\PushBillToAcumaticaAction;
use Kanvas\Connectors\Acumatica\Approvals\ReadsApprovalSourceFields;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Actions\NotifyApproverAction;
use Kanvas\Scribe\Approvals\Actions\ResolveApproverEmailAction;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Bills\Actions\ApproveBillAction;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Creates an AP bill (one line, or several via lines) and, by default, auto-approves it and pushes it to Acumatica in one step. */
#[AgentTool(name: 'Create AP Bill', category: 'accounting')]
class CreateApBillTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReadsApprovalSourceFields;
    use StoresApprovalSourceFields;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_ap_bill',
            description: 'Creates an AP bill — one line via amount/gl_account_number, or several via lines when '
                . 'the invoice has more than one line item. By default also auto-approves it and pushes it to '
                . 'Acumatica in one step, returning the Acumatica bill reference — bypassing the normal human '
                . 'approval gate, so only do this when the user explicitly asks to create a bill this way, never '
                . 'on a whim. Set push_to_acumatica to false to just create the bill and submit it for approval '
                . '(status: pending_approval) without touching Acumatica — this is the default for the standard '
                . 'automatic invoice-processing flow, where a human approves it later and the push happens '
                . 'separately.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'amount',
                type: PropertyType::NUMBER,
                description: 'The bill amount, e.g. 1.00. Required only for a one-line bill — omit it and use '
                    . 'lines instead when the invoice has more than one line item.',
                required: false,
            ),
            new ToolProperty(
                name: 'gl_account_number',
                type: PropertyType::STRING,
                description: 'The GL expense account number to code the line to, e.g. "71610". Required only '
                    . 'for a one-line bill — omit it and use lines instead when the invoice has more than one '
                    . 'line item.',
                required: false,
            ),
            new ToolProperty(
                name: 'memo',
                type: PropertyType::STRING,
                description: 'Description / memo for the bill overall. Also used as a line\'s own description '
                    . 'when that line does not specify one.',
                required: true,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'One entry per line item, when the invoice has more than one. Never combine '
                    . 'several invoice lines into one — list them exactly as they appear on the invoice. Omit '
                    . 'entirely for a one-line bill and use the singular amount/gl_account_number instead.',
                required: false,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'A single bill line.',
                    properties: [
                        new ToolProperty(
                            name: 'gl_account_number',
                            type: PropertyType::STRING,
                            description: 'The GL expense account number for this line, e.g. "71610".',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'amount',
                            type: PropertyType::NUMBER,
                            description: 'This line\'s amount.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'Line description, e.g. the product/service name. Defaults to the '
                                . 'bill-level memo when omitted.',
                            required: false,
                        ),
                        new ToolProperty(
                            name: 'subaccount',
                            type: PropertyType::STRING,
                            description: 'Subaccount for this line specifically. Defaults to the top-level '
                                . 'subaccount param when omitted.',
                            required: false,
                        ),
                    ],
                ),
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
                name: 'due_date',
                type: PropertyType::STRING,
                description: 'The invoice\'s own due/pay-by date, "YYYY-MM-DD" — e.g. extract_invoice_data\'s '
                    . 'own due_date field, when the invoice shows one. Never compute or guess this yourself; '
                    . 'omit it when the invoice does not show a due date.',
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
                name: 'source_attachment_filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of the invoice PDF (from download_attachment), when created as '
                    . 'part of the automatic invoice-email flow. Attaches the PDF to the bill via Kanvas '
                    . 'Filesystem so it can be forwarded to the approver and pushed to Acumatica.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, array{gl_account_number?: string, amount?: float|int|string, description?: string, subaccount?: string}>|null $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $vendor_name,
        string $memo,
        string $invoice_number,
        ?float $amount = null,
        ?string $gl_account_number = null,
        ?array $lines = null,
        ?string $subaccount = null,
        ?string $due_date = null,
        ?string $currency = null,
        ?bool $push_to_acumatica = null,
        ?string $source_email_message_id = null,
        ?int $source_attachment_filesystem_id = null,
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

        if (($lines === null || $lines === []) && ($amount === null || trim((string) $gl_account_number) === '')) {
            return [
                'created' => false,
                'reason' => 'lines_or_amount_required',
                'message' => 'Provide either lines (multi-line) or both amount and gl_account_number (one-line).',
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
        $vendorDisplayName = trim((string) $vendor->get(OrganizationApproverCustomFieldEnum::VENDOR_NAME->value, '')) ?: $vendor->name;

        $lineInputs = $lines !== null && $lines !== [] ? $lines : [[
            'gl_account_number' => $gl_account_number,
            'amount' => $amount,
            'description' => $memo,
            'subaccount' => $subaccount,
        ]];

        $built = $this->buildBillLines(
            lineInputs: $lineInputs,
            fallbackDescription: $memo,
            fallbackSubaccount: $subaccount,
            app: $app,
            company: $company,
        );

        if (isset($built['error'])) {
            return $built['error'];
        }

        $billLines = $built['lines'];
        $totalAmount = $built['total'];

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;
        $parsedDueDate = $this->parseDueDate($due_date);

        $bill = new CreateBillAction(
            new BillData(
                app: $app,
                company: $company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, $billLines),
                currency: $currency,
                fx_rate_to_base: 1.0,
                bill_number: $invoice_number,
                bill_date: Carbon::today(),
                due_date: $parsedDueDate,
                notes: $memo,
            ),
            $actingUser,
        )->execute();

        $bill = new SubmitBillForApprovalAction($bill, $actingUser)->execute();

        $this->storeApprovalSourceFields(
            $bill,
            $source_email_message_id,
            $source_attachment_filesystem_id,
        );

        if (! $push_to_acumatica) {
            $approverEmails = ResolveApproverEmailAction::resolveForOrganization($vendor);
            $isMultiLine = $lines !== null && $lines !== [];
            $sourceFields = $this->sourceFields($bill);
            $hasAttachment = $sourceFields['source_attachment_url'] !== null;
            $hasSourceEmail = $source_email_message_id !== null && trim($source_email_message_id) !== '';

            NotifyApproverAction::notifyAll(
                approverEmails: $approverEmails,
                app: $app,
                text: "You have an AP bill pending approval:\nVendor: {$vendorDisplayName}\nAmount: {$currency} "
                    . "{$totalAmount}\n" . ($isMultiLine ? $this->lineSummaryText($lineInputs) : "GL: {$gl_account_number}"
                        . ($subaccount !== null && trim($subaccount) !== '' ? " / Subaccount: {$subaccount}" : ''))
                    . "\nMemo: {$memo}\nBill ID (Kanvas): {$bill->getId()}\n\nReply \"approve bill "
                    . "{$bill->getId()}\" to approve it and push it to Acumatica.",
                attachmentUrl: $sourceFields['source_attachment_url'],
                attachmentFilename: $sourceFields['source_attachment_filename'],
            );

            $result = [
                'created' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'bill_number' => $bill->bill_number,
                'document_status' => $bill->document_status->value,
                'vendor' => $vendorDisplayName,
                'amount' => $totalAmount,
                'currency' => $currency,
                'gl_account' => $gl_account_number,
                'subaccount' => $subaccount,
                'memo' => $memo,
                'approved_by_flag' => $approverEmails !== [] ? '' : 'NOT IN APPROVER LIST',
                'next' => $approverEmails !== []
                    ? 'Bill created and submitted for approval in Kanvas (status: pending_approval). Not pushed '
                        . 'to Acumatica — that happens separately once a human approves it.'
                    : 'Bill created and submitted for approval, but vendor "' . $vendorDisplayName . '" has no '
                        . 'approver configured — nobody can approve it and no notification was sent. Write '
                        . 'approved_by_flag into the sheet\'s Approved By column so this is visible there too, '
                        . 'and tell the user to have an admin set that vendor\'s approver.',
            ];

            if ($isMultiLine) {
                $result['lines'] = array_map(
                    static fn (array $line): array => [
                        'gl_account_number' => (string) ($line['gl_account_number'] ?? ''),
                        'amount' => (float) ($line['amount'] ?? 0),
                        'description' => (string) ($line['description'] ?? $memo),
                    ],
                    $lineInputs,
                );
            }

            if ($hasSourceEmail && ! $hasAttachment) {
                $result['attachment_warning'] = 'This bill has a source email but no source_attachment_filesystem_id '
                    . '— the approver was notified without the invoice PDF attached.';
            }

            return $result;
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
            'vendor' => $vendorDisplayName,
            'amount' => $totalAmount,
            'currency' => $currency,
            'gl_account' => $gl_account_number,
            'subaccount' => $subaccount,
            'memo' => $memo,
            'bill_ref' => $reference,
            'acumatica_bill_id' => (string) $bill->get(AcumaticaCustomFieldEnum::BILL_ID->value, ''),
            'next' => 'Pushed to Acumatica. bill_ref is the ERP reference.',
        ];
    }

    /**
     * @param array<int, array{gl_account_number?: string, amount?: float|int|string, description?: string, subaccount?: string}> $lineInputs
     *
     * @return array{lines: list<BillLineData>, total: float}|array{error: array<string, mixed>}
     */
    private function buildBillLines(
        array $lineInputs,
        string $fallbackDescription,
        ?string $fallbackSubaccount,
        Apps $app,
        Companies $company,
    ): array {
        $lines = [];
        $total = 0.0;

        foreach ($lineInputs as $line) {
            $lineGlAccount = trim((string) ($line['gl_account_number'] ?? ''));
            $lineAmount = (float) ($line['amount'] ?? 0);

            if ($lineGlAccount === '' || $lineAmount <= 0) {
                return ['error' => [
                    'created' => false,
                    'reason' => 'line_invalid',
                    'message' => 'Every line needs a gl_account_number and a positive amount.',
                ]];
            }

            $account = Account::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('account_number', $lineGlAccount)
                ->where('is_active', true)
                ->first();

            if ($account === null) {
                return ['error' => [
                    'created' => false,
                    'reason' => 'account_not_found',
                    'message' => "No active GL account with number \"{$lineGlAccount}\" for this app/company.",
                ]];
            }

            $lineSubaccount = trim((string) ($line['subaccount'] ?? '')) ?: $fallbackSubaccount;

            $lines[] = new BillLineData(
                description: (string) ($line['description'] ?? $fallbackDescription),
                quantity: 1.0,
                unit_price_native: $lineAmount,
                expense_account_id: $account->getId(),
                subaccount_id: $this->resolveSubaccountId($lineSubaccount, $app, $company),
            );
            $total += $lineAmount;
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /**
     * @param array<int, array{gl_account_number?: string, amount?: float|int|string, description?: string}> $lineInputs
     */
    private function lineSummaryText(array $lineInputs): string
    {
        $rows = array_map(
            static fn (array $line): string => "GL {$line['gl_account_number']}: "
                . number_format((float) ($line['amount'] ?? 0), 2)
                . (isset($line['description']) ? " - {$line['description']}" : ''),
            $lineInputs,
        );

        return "Lines:\n" . implode("\n", $rows);
    }

    private function parseDueDate(?string $due_date): ?Carbon
    {
        if ($due_date === null || trim($due_date) === '') {
            return null;
        }

        try {
            return Carbon::parse($due_date);
        } catch (Throwable) {
            return null;
        }
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
