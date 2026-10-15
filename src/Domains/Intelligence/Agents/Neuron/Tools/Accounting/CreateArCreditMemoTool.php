<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Scribe\Approvals\Enums\OrganizationApproverCustomFieldEnum;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Ledger\Models\Account;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Spatie\LaravelData\DataCollection;
use Throwable;

/** Issues a standalone AR credit memo (e.g. a back-end rebate) directly in the Kanvas ledger. */
#[AgentTool(name: 'Create AR Credit Memo', category: 'accounting')]
class CreateArCreditMemoTool extends Tool
{
    use HasKanvasContext;
    use ResolvesCustomerForTool;
    use TrackByInputs;

    protected string $name = 'create_ar_credit_memo';

    protected ?string $description = 'Issues a standalone AR credit memo for a customer — e.g. a back-end rebate/sell-out '
        . 'allowance from a Credit Request Form — directly in the Kanvas ledger. It is not '
        . 'tied to any specific invoice. Only call when the user explicitly asks to issue a credit, never '
        . 'on a whim.';

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Customer name to match (substring), e.g. "Proshop". Always required — never '
                    . 'guess or pick an arbitrary customer; ask the user if it is not clear from context.',
                required: true,
            ),
            new ToolProperty(
                name: 'invoice_number',
                type: PropertyType::STRING,
                description: 'The Request Reference No. from the Credit Request Form (e.g. "Proshop Superdays '
                    . 'Sell-Out (22/05-07/06)") — used as this credit memo\'s own document number, never a lookup '
                    . 'into an existing invoice. Always required.',
                required: true,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'One entry per row on the Credit Request Form. At least one line is required.',
                required: true,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'A single credit memo line.',
                    properties: [
                        new ToolProperty(
                            name: 'control_account_number',
                            type: PropertyType::STRING,
                            description: 'The "Control Acct#" from the form, e.g. "41045".',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'amount',
                            type: PropertyType::NUMBER,
                            description: 'The credited amount for this line.',
                            required: true,
                        ),
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'Line description, e.g. the product name.',
                            required: false,
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'notes',
                type: PropertyType::STRING,
                description: 'Accounting-relevant context from the request email\'s own wording that is not '
                    . 'already captured by the form\'s fields — e.g. why no VAT applies, or an approval statement '
                    . 'from the sender. Use the email\'s own wording, never invent one. Omit when the email has '
                    . 'nothing beyond the routine request.',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, array{control_account_number?: string, amount?: float|int|string, description?: string}> $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $customer_name,
        string $invoice_number,
        array $lines,
        ?string $currency = null,
        ?string $notes = null,
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

        if (trim($invoice_number) === '') {
            return [
                'created' => false,
                'reason' => 'invoice_number_required',
                'message' => 'An invoice_number (the Credit Request Form\'s Request Reference No.) is required.',
            ];
        }

        if ($lines === []) {
            return [
                'created' => false,
                'reason' => 'lines_required',
                'message' => 'At least one line (control_account_number + amount) is required.',
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

        $lineData = [];
        foreach ($lines as $line) {
            $accountNumber = trim((string) ($line['control_account_number'] ?? ''));

            if ($accountNumber === '') {
                return [
                    'created' => false,
                    'reason' => 'control_account_number_required',
                    'message' => 'Every line requires a control_account_number.',
                ];
            }

            $account = $this->resolveAccount($accountNumber, $app, $company);

            if ($account === null) {
                return [
                    'created' => false,
                    'reason' => 'account_not_found',
                    'message' => "No active GL account with number \"{$accountNumber}\" for this app/company.",
                ];
            }

            $lineData[] = new InvoiceLineData(
                description: (string) ($line['description'] ?? $accountNumber),
                quantity: 1.0,
                unit_price_native: (float) ($line['amount'] ?? 0),
                account_id: $account->getId(),
            );
        }

        $currency = $currency !== null && trim($currency) !== '' ? strtoupper(trim($currency)) : 'USD';
        $actingUser = $this->user;

        try {
            $creditNote = new IssueCreditNoteAction(
                parentInvoice: null,
                data: new InvoiceData(
                    app: $app,
                    company: $company,
                    billable: $customer,
                    lines: new DataCollection(InvoiceLineData::class, $lineData),
                    currency: $currency,
                    fx_rate_to_base: 1.0,
                    invoice_number: trim($invoice_number),
                    notes: trim((string) $notes) !== ''
                        ? "Credit Request Form reference: {$invoice_number}\n" . trim((string) $notes)
                        : "Credit Request Form reference: {$invoice_number}",
                ),
                billable: $customer,
                user: $actingUser,
            )->execute();
        } catch (Throwable $e) {
            return [
                'created' => false,
                'reason' => 'issue_failed',
                'message' => 'Could not issue the credit memo: ' . $e->getMessage(),
            ];
        }

        return [
            'created' => true,
            'issued' => true,
            'credit_memo_id' => $creditNote->getId(),
            'credit_memo_number' => $creditNote->invoice_number,
            'customer' => $customerDisplayName,
            'amount' => (float) $creditNote->total_native,
            'currency' => $currency,
            'processed_at' => Carbon::now()->toDateTimeString(),
            'next' => 'Credit memo issued in the Kanvas ledger. Any external synchronization is handled by '
                . 'configured workflows. processed_at is the exact time this ran — copy it verbatim if logging '
                . 'this to a sheet, never invent a timestamp yourself. Use add_invoice_note or attach_invoice_file '
                . 'if you need to attach the request form or manager approval.',
        ];
    }

    private function resolveAccount(string $accountNumber, Apps $app, Companies $company): ?Account
    {
        return Account::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('account_number', $accountNumber)
            ->where('is_active', true)
            ->first();
    }
}
