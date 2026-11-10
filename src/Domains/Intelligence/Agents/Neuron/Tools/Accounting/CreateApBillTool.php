<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\DateHelper;
use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationVendorMatcherService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\StoresApprovalSourceFields;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Approvals\Traits\ReadsApprovalSourceFields;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\SubmitBillForApprovalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\DataTransferObject\ExpenseAccountCoding;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Exceptions\DuplicateBillNumberException;
use Kanvas\Scribe\Bills\Services\BillAddresseeService;
use Kanvas\Scribe\Bills\Services\ExpenseAccountCodingService;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\PdfIngest\Enums\PdfExtractionCustomFieldEnum;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;

/** Creates an AP bill (one line, or several via lines) and submits it for approval; it never approves. */
#[AgentTool(name: 'Create AP Bill', category: 'accounting')]
class CreateApBillTool extends Tool
{
    use HasKanvasContext;
    use ReadsApprovalSourceFields;
    use ResolvesFilesystemForTool;
    use StoresApprovalSourceFields;
    use TrackByInputs;

    protected string $name = 'create_ap_bill';

    /**
     * The person's answer to a bill-to mismatch, kept on the bill for the approver; never acted on by the agent.
     *
     * @var array<string, string>|null
     */
    private ?array $addresseeOverride = null;

    protected ?string $description = 'Creates an AP bill — one line via amount, or several via lines when the invoice has '
        . 'more than one line item — dated by invoice_date, and submits it for human approval (status: pending_approval), '
        . 'which notifies the approver. A line with no gl_account_number is coded from the vendor\'s default or history, '
        . 'the item catalog or an AI suggestion the approver checks. An invoice already paid by card is refused: that is a '
        . 'company card expense, not a payable. An agent never approves its own bill: approve_immediately is accepted but '
        . 'still goes through approval. External provider synchronization is handled separately by configured '
        . 'workflow rules.';

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
                description: 'The GL expense account number for a one-line bill, only when the person named one '
                    . '(list_gl_accounts shows the chart). Leave it out otherwise: the line is coded from the vendor\'s '
                    . 'default or history, the item catalog or an AI suggestion the approver checks. Never copy a '
                    . 'number from another bill.',
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
                            description: 'The GL expense account number for this line, only when the person named one; '
                                . 'left out, the line is coded automatically.',
                            required: false,
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
                    . 'vendor reference. Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'subaccount',
                type: PropertyType::STRING,
                description: 'Subaccount code for the line, e.g. "BB-0G-M1", when the vendor or project requires '
                    . 'a specific one. Optional.',
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
                name: 'invoice_date',
                type: PropertyType::STRING,
                description: 'The invoice\'s own issue date, "YYYY-MM-DD" — extract_invoice_data\'s issue_date. The bill '
                    . 'is dated and aged by it; left out, it is dated today.',
                required: false,
            ),
            new ToolProperty(
                name: 'payment_status_hint',
                type: PropertyType::STRING,
                description: 'extract_invoice_data\'s payment_status_hint, verbatim: paid, unpaid or unknown.',
                required: false,
            ),
            new ToolProperty(
                name: 'payment_method_hint',
                type: PropertyType::STRING,
                description: 'extract_invoice_data\'s payment_method_hint, verbatim (e.g. credit_card, bank_transfer). A '
                    . 'paid card receipt is refused here: book it with record_company_card_expense instead.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'billed_to',
                type: PropertyType::STRING,
                description: 'The company the invoice is addressed to, exactly as printed under "Bill to" / '
                    . '"Facturar a" / "Cliente". Read it off the invoice every time; "" only when the invoice names '
                    . 'no customer. A bill addressed to another company is refused until the person answers.',
                required: true,
            ),
            new ToolProperty(
                name: 'billed_to_tax_id',
                type: PropertyType::STRING,
                description: 'The tax id printed with the bill-to (RNC, EIN), when there is one.',
                required: false,
            ),
            new ToolProperty(
                name: 'billed_to_is_this_company',
                type: PropertyType::BOOLEAN,
                description: 'Only after the person confirmed the bill-to name is this company under another name '
                    . '(legal name, DBA, old name). It is remembered, so the next invoice with that name is not asked.',
                required: false,
            ),
            new ToolProperty(
                name: 'book_anyway',
                type: PropertyType::BOOLEAN,
                description: 'Only after the person said to book this one invoice here although it is addressed to '
                    . 'another company. Nothing is remembered.',
                required: false,
            ),
            new ToolProperty(
                name: 'approve_immediately',
                type: PropertyType::BOOLEAN,
                description: 'Ignored for an agent: the bill is always submitted for approval, and the approver '
                    . 'signs it with approve_pending_item. Kept so older prompts still validate.',
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
                    . 'Filesystem so it can be forwarded to the approver and seen later.',
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
        ?bool $approve_immediately = null,
        ?string $source_email_message_id = null,
        ?int $source_attachment_filesystem_id = null,
        ?string $billed_to = null,
        ?string $billed_to_tax_id = null,
        ?bool $billed_to_is_this_company = null,
        ?bool $book_anyway = null,
        ?string $invoice_date = null,
        ?string $payment_status_hint = null,
        ?string $payment_method_hint = null,
    ): array {
        // The attached invoice outranks the model's retelling of it: an agent once passed its own company as the
        // bill-to on an invoice addressed to a person, and the check had nothing else to compare against.
        $extracted = $this->storedExtraction($source_attachment_filesystem_id);
        $addressee = $this->checkAddressee(
            Str::trimToNull($extracted['bill_to_name'] ?? null) ?? $billed_to,
            Str::trimToNull($extracted['bill_to_tax_id'] ?? null) ?? $billed_to_tax_id,
            $billed_to_is_this_company ?? false,
            $book_anyway ?? false
        );
        if ($addressee !== null) {
            return $addressee;
        }

        return ApprovalOriginService::during(
            ApprovalOriginEnum::AGENT,
            fn (): array => $this->createBill(
                $vendor_name,
                $memo,
                $invoice_number,
                $amount,
                $gl_account_number,
                $lines,
                $subaccount,
                $due_date,
                $currency,
                $approve_immediately ?? false,
                $source_email_message_id,
                $source_attachment_filesystem_id,
                $invoice_date,
                $payment_status_hint,
                $payment_method_hint,
            )
        );
    }

    /**
     * @param array<int, array{gl_account_number?: string, amount?: float|int|string, description?: string, subaccount?: string}>|null $lines
     *
     * @return array<string, mixed>
     */
    private function createBill(
        string $vendor_name,
        string $memo,
        string $invoice_number,
        ?float $amount,
        ?string $gl_account_number,
        ?array $lines,
        ?string $subaccount,
        ?string $due_date,
        ?string $currency,
        bool $approve_immediately,
        ?string $source_email_message_id,
        ?int $source_attachment_filesystem_id,
        ?string $invoice_date,
        ?string $payment_status_hint,
        ?string $payment_method_hint,
    ): array {
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

        if (($lines === null || $lines === []) && $amount === null) {
            return [
                'created' => false,
                'reason' => 'lines_or_amount_required',
                'message' => 'Provide either lines (multi-line) or amount (one-line).',
            ];
        }

        $paymentStatus = PaymentStatusHintEnum::tryFrom(strtolower((string) Str::trimToNull($payment_status_hint)));
        $paymentMethod = strtolower((string) Str::trimToNull($payment_method_hint));

        if ($paymentStatus === PaymentStatusHintEnum::PAID && str_contains($paymentMethod, 'card')) {
            return [
                'created' => false,
                'reason' => 'paid_by_card',
                'message' => "The invoice shows it was already paid by card ({$paymentMethod}). A paid card charge is not a "
                    . 'payable, so nothing was created. Book it as a company card expense: extract_expense_receipt on the '
                    . 'same file, then record_company_card_expense with the amount, date and merchant it returns. That '
                    . 'tool loads only when a person is asking; on an unattended turn, report the invoice and leave it.',
            ];
        }

        [$invoiceDate, $invalidDate] = $this->dateArgument('invoice_date', $invoice_date, 'to date the bill today');

        if ($invalidDate !== null) {
            return $invalidDate;
        }

        [$dueDate, $invalidDate] = $this->dateArgument('due_date', $due_date, 'when the invoice shows none');

        if ($invalidDate !== null) {
            return $invalidDate;
        }

        $match = OrganizationVendorMatcherService::match($app, $company, $vendor_name);

        if (! $match->isMatched()) {
            return [
                'created' => false,
                'reason' => $match->candidates !== [] ? 'vendor_ambiguous' : 'vendor_not_found',
                'message' => $match->candidates !== []
                    ? "\"{$vendor_name}\" could match more than one vendor: "
                        . implode(', ', array_map(static fn (Organization $o): string => $o->name, $match->candidates))
                        . '. Call find_vendor to confirm the right vendor with the user.'
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
            $lineInputs,
            $memo,
            $subaccount,
            $vendor,
            $vendorDisplayName
        );

        if (isset($built['error'])) {
            return $built['error'];
        }

        $billLines = $built['lines'];
        $totalAmount = $built['total'];
        $glAccountNumbers = $built['gl_account_numbers'];
        $coding = $built['coding'];

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;

        try {
            $bill = new CreateBillAction(
                new BillData(
                    app: $app,
                    company: $company,
                    vendor: $vendor,
                    lines: new DataCollection(BillLineData::class, $billLines),
                    currency: $currency,
                    fx_rate_to_base: 1.0,
                    bill_number: $invoice_number,
                    bill_date: $invoiceDate ?? Carbon::today(),
                    due_date: $dueDate,
                    notes: $memo,
                ),
                $actingUser,
            )->execute();
        } catch (DuplicateBillNumberException $e) {
            return $this->duplicateInvoiceResult($e, $vendorDisplayName);
        }

        // Before submitting: opening the approval request DMs the approver, and the PDF has to be on the
        // bill by then to ride along.
        $this->storeApprovalSourceFields($bill, $source_email_message_id, $source_attachment_filesystem_id);

        if ($this->addresseeOverride !== null) {
            $bill->metadata = [...($bill->metadata ?? []), BillAddresseeService::METADATA_KEY => $this->addresseeOverride];
        }

        if ($paymentStatus !== null) {
            $bill->payment_status_hint = $paymentStatus;
        }

        // The approver is the check on an AI coding, so the approval carries how sure it was.
        if ($coding !== null && $coding->isSuggestion()) {
            $bill->appendInternalNote(sprintf(
                'Expense account suggested by AI (confidence %d%%): %s Check the coding before approving.',
                (int) round($coding->confidence * 100),
                (string) $coding->reasoning,
            ));
        }

        if ($bill->isDirty()) {
            $bill->saveOrFail();
        }

        // Agent origin always goes through approval. Approving here would bypass the request the submit just
        // opened (and notified); a person approves directly through the UI, never through this tool.
        $bill = new SubmitBillForApprovalAction($bill, $actingUser)->execute();

        // The policy routes the request (vendor approver, else the setup's default approver); the deprecated
        // per-vendor lookup alone said "nobody can approve it" for requests that were assigned and notified.
        $request = $bill->pendingApproval();
        $routed = $request !== null && ! $request->isUnassigned();
        $isMultiLine = $lines !== null && $lines !== [];
        $sourceFields = $this->sourceFields($bill);
        $hasAttachment = $sourceFields['source_attachment_url'] !== null;
        $hasSourceEmail = $source_email_message_id !== null && trim($source_email_message_id) !== '';

        $result = [
            'created' => true,
            'bill_id' => $bill->getId(),
            'bill_number' => $bill->bill_number,
            'document_status' => $bill->document_status->value,
            'vendor' => $vendorDisplayName,
            'amount' => $totalAmount,
            'currency' => $currency,
            'bill_date' => $bill->bill_date?->toDateString(),
            'due_date' => $bill->due_date?->toDateString(),
            'gl_account' => count(array_unique($glAccountNumbers)) === 1 ? $glAccountNumbers[0] : null,
            'subaccount' => $subaccount,
            'memo' => $memo,
            'payment_status_hint' => $bill->payment_status_hint->value,
            'remaining_balance' => (float) $bill->balance_due_native,
            'approved_by_flag' => $routed || $request === null ? '' : 'NOT IN APPROVER LIST',
            'next' => match (true) {
                $request === null => 'Bill created; no approval step applied.',
                $routed => 'Bill created and submitted for approval in Kanvas (status: pending_approval); the approver was notified.',
                default => 'Bill created and submitted for approval, but no approver could be resolved for vendor "'
                    . $vendorDisplayName . '" — nobody was notified. Tell the user to have an admin set an approver '
                    . '(the vendor\'s, or the accounting default approver).',
            },
        ];

        if ($isMultiLine) {
            $result['lines'] = array_map(
                static fn (array $line, string $glAccountNumber): array => [
                    'gl_account_number' => $glAccountNumber,
                    'amount' => (float) ($line['amount'] ?? 0),
                    'description' => (string) ($line['description'] ?? $memo),
                ],
                array_values($lineInputs),
                $glAccountNumbers,
            );
        }

        if ($coding !== null) {
            $result['gl_coding'] = [
                'source' => $coding->source,
                'confidence' => $coding->confidence,
                'reasoning' => $coding->reasoning,
            ];

            if ($coding->isSuggestion()) {
                $result['coding_note'] = sprintf(
                    'The expense account was suggested by AI (confidence %d%%), not taken from the vendor or the invoice: '
                    . 'name the account to the person and say the approver checks the coding.',
                    (int) round($coding->confidence * 100),
                );
            }
        }

        if ($paymentStatus === PaymentStatusHintEnum::PAID) {
            $result['payment_note'] = 'The vendor shows this invoice as already paid. It still waits for its approver; once '
                . 'approved, record the payment with record_bill_payment using the reference the person gives, or let '
                . 'the bank statement match it. Say it is pending approval and marked as already paid.';
        }

        if ($hasSourceEmail && ! $hasAttachment) {
            $result['attachment_warning'] = 'This bill has a source email but no source_attachment_filesystem_id '
                . '— the approver was notified without the invoice PDF attached.';
        }

        if ($approve_immediately) {
            $result['approval_note'] = 'approve_immediately was ignored: a bill you create always waits for its '
                . 'approver. Tell the user it is pending approval, not approved.';
        }

        return $result;
    }

    /**
     * A line without a gl_account_number is coded the way the inbox codes an uncoded PDF (vendor default or
     * history, the item catalog, then an AI suggestion the approver checks): a model picking a number off another
     * bill is how a software subscription landed on Bank Fees.
     *
     * @param array<int, array{gl_account_number?: string, amount?: float|int|string, description?: string, subaccount?: string}> $lineInputs
     *
     * @return array{lines: list<BillLineData>, total: float, gl_account_numbers: list<string>, coding: ExpenseAccountCoding|null}|array{error: array<string, mixed>}
     */
    private function buildBillLines(
        array $lineInputs,
        string $fallbackDescription,
        ?string $fallbackSubaccount,
        Organization $vendor,
        string $vendorDisplayName,
    ): array {
        $needsCoding = array_any(
            $lineInputs,
            static fn (array $line): bool => trim((string) ($line['gl_account_number'] ?? '')) === ''
        );
        $coding = $needsCoding
            ? new ExpenseAccountCodingService()->code(
                $this->app,
                $this->company,
                $vendor,
                $vendorDisplayName,
                $this->lineDescriptions($lineInputs, $fallbackDescription)
            )
            : null;
        $codedAccount = $coding !== null ? Account::query()->whereKey($coding->accountId)->first() : null;

        $lines = [];
        $numbers = [];
        $total = 0.0;

        foreach ($lineInputs as $line) {
            $lineGlAccount = trim((string) ($line['gl_account_number'] ?? ''));
            $lineAmount = (float) ($line['amount'] ?? 0);
            $description = (string) ($line['description'] ?? $fallbackDescription);

            if ($lineAmount <= 0) {
                return ['error' => [
                    'created' => false,
                    'reason' => 'line_invalid',
                    'message' => 'Every line needs a positive amount.',
                ]];
            }

            if ($lineGlAccount === '' && $codedAccount === null) {
                return ['error' => [
                    'created' => false,
                    'reason' => 'account_required',
                    'message' => "No GL account was given for \"{$description}\" and none could be coded for "
                        . "{$vendorDisplayName}. Nothing was created: ask the person which expense account it belongs "
                        . 'to (list_gl_accounts shows the chart) and call again with gl_account_number on that line.',
                ]];
            }

            $account = $lineGlAccount !== ''
                ? Account::activeByNumber($this->app->getId(), $this->company->getId(), $lineGlAccount)
                : $codedAccount;

            if ($account === null) {
                return ['error' => [
                    'created' => false,
                    'reason' => 'account_not_found',
                    'message' => "No active GL account with number \"{$lineGlAccount}\" for this app/company.",
                ]];
            }

            $lineSubaccount = trim((string) ($line['subaccount'] ?? '')) ?: $fallbackSubaccount;

            $lines[] = new BillLineData(
                description: $description,
                quantity: 1.0,
                unit_price_native: $lineAmount,
                expense_account_id: $account->getId(),
                subaccount_id: $this->resolveSubaccountId($lineSubaccount),
            );
            $numbers[] = (string) $account->account_number;
            $total += $lineAmount;
        }

        return ['lines' => $lines, 'total' => $total, 'gl_account_numbers' => $numbers, 'coding' => $coding];
    }

    /**
     * The parsed date, or the refusal to hand back: Carbon::parse() would turn "next week" or 2026-02-30 into some
     * other day, and a bill dated or due on the wrong day is worse than none.
     *
     * @return array{0: Carbon|null, 1: array<string, mixed>|null}
     */
    private function dateArgument(string $name, ?string $value, string $whenOmitted): array
    {
        $day = Str::trimToNull($value);

        if ($day === null) {
            return [null, null];
        }

        $parsed = DateHelper::parseYmd($day);

        if ($parsed === null) {
            return [null, [
                'created' => false,
                'reason' => "invalid_{$name}",
                'message' => "{$name} \"{$day}\" is not a date in YYYY-MM-DD form. Nothing was created: pass the invoice's "
                    . "own {$name} as YYYY-MM-DD, or omit it {$whenOmitted}.",
            ]];
        }

        return [Carbon::instance($parsed), null];
    }

    /**
     * @param array<int, array{description?: string}> $lineInputs
     *
     * @return list<string>
     */
    private function lineDescriptions(array $lineInputs, string $fallbackDescription): array
    {
        return array_values(array_filter(array_map(
            static fn (array $line): string => trim((string) ($line['description'] ?? $fallbackDescription)),
            $lineInputs,
        )));
    }

    /**
     * @return array<string, mixed>
     */
    private function duplicateInvoiceResult(DuplicateBillNumberException $e, string $vendorDisplayName): array
    {
        $existing = $e->existing;

        return [
            'created' => false,
            'reason' => 'duplicate_invoice',
            'message' => "Invoice {$e->billNumber} from {$vendorDisplayName} was already recorded"
                . ($existing !== null ? " as bill {$existing->getId()}" : '')
                . ($existing?->is_deleted ? ', which was deleted — its invoice number cannot be reused' : '')
                . '. No new bill was created; tell the user instead of retrying.',
            'bill_id' => $existing?->getId(),
            'document_status' => $existing?->document_status->value,
            'is_deleted' => $existing?->is_deleted,
        ];
    }

    private function resolveSubaccountId(?string $subaccount): ?int
    {
        if ($subaccount === null || trim($subaccount) === '') {
            return null;
        }

        $subaccountModel = Subaccount::firstOrCreate(
            [
                'apps_id' => $this->app->getId(),
                'companies_id' => $this->company->getId(),
                'sub_code' => trim($subaccount),
            ],
            ['source' => 'kanvas'],
        );

        return (int) $subaccountModel->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function storedExtraction(?int $filesystemId): array
    {
        if ($filesystemId === null) {
            return [];
        }

        $stored = $this->findTenantFile($filesystemId)?->get(PdfExtractionCustomFieldEnum::EXTRACTED->value);

        return is_array($stored) && is_array($stored['extracted'] ?? null) ? $stored['extracted'] : [];
    }

    /**
     * Null when the bill may be booked here; otherwise the refusal to hand back. An invoice that names no customer
     * ("") is not checked: there is nothing to compare.
     *
     * @return array<string, mixed>|null
     */
    private function checkAddressee(
        ?string $billedTo,
        ?string $billedToTaxId,
        bool $isThisCompany,
        bool $bookAnyway
    ): ?array {
        $billedTo = Str::trimToNull($billedTo);
        $service = new BillAddresseeService();

        if ($billedTo === null || $service->isAddressedTo($this->company, $billedTo, $billedToTaxId)) {
            return null;
        }

        // The agent can set these flags without asking (seen live), so neither teaches the company a name: the bill
        // still waits for a person's approval, carries the mismatch to that approver, and the name is remembered
        // only when they approve it (ApproveBillAction).
        if ($isThisCompany || $bookAnyway) {
            $this->addresseeOverride = [
                'billed_to' => $billedTo,
                'this_company' => (string) $this->company->name,
                'claimed' => $isThisCompany ? BillAddresseeService::CLAIMED_SAME_COMPANY : 'book_anyway',
            ];

            return null;
        }

        $other = $this->user instanceof Users ? $service->otherCompanyOf($this->user, $this->company, $billedTo) : null;

        return [
            'created' => false,
            'reason' => 'billed_to_other_company',
            'billed_to' => $billedTo,
            'this_company' => $this->company->name,
            'other_company' => $other !== null ? ['company_id' => $other->getId(), 'name' => $other->name] : null,
            'message' => "This invoice is addressed to \"{$billedTo}\", not {$this->company->name}. Nothing was created. "
                . ($other !== null
                    ? "It looks like {$other->name}'s invoice: it should be booked in that company's books (its own AP agent), not here. "
                    : '')
                . 'Ask the person: is it this company under another name (call again with billed_to_is_this_company: '
                . 'true, remembered), or should this one invoice be booked here anyway (book_anyway: true)?',
        ];
    }
}
