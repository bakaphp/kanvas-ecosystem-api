<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Services\OpenInvoiceMatcherService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The AR cash-matching helper (UC2): given a customer and a received-payment amount (a bank deposit /
 * remittance), lists that customer's OPEN invoices and flags an exact single-invoice match. The agent
 * uses this to PROPOSE the cash application; a human confirms before anything posts. Read-only.
 */
#[AgentTool(name: 'Match Invoices For Payment', category: 'accounting')]
class MatchInvoicesForPaymentTool extends Tool
{
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'match_invoices_for_payment';

    protected ?string $description = 'Given a customer name and a payment amount, lists the customer\'s open (issued, unpaid) '
        . 'invoices and flags an exact single-invoice match. Use this to propose how a received AR payment '
        . 'applies to invoices before recording it. Read-only — proposes, does not apply.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'customer', type: PropertyType::STRING, description: 'Customer name (as on the remittance).', required: true),
            new ToolProperty(name: 'amount', type: PropertyType::NUMBER, description: 'The payment amount to match.', required: true),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max invoices to return. Default 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $customer, float $amount, ?int $limit = null): array
    {
        $match = new OpenInvoiceMatcherService()->match(
            app: $this->app,
            company: $this->company,
            customer: $customer,
            amount: $amount,
            limit: max(1, min(100, $limit ?? 25)),
        );

        if (! $match['customer_found']) {
            return ['customer' => $customer, 'amount' => $amount, 'open_invoices' => [], 'reason' => 'No customer matched that name.'];
        }

        return [
            'customer' => $customer,
            'amount' => $amount,
            'exact_match' => $match['exact_match'],
            'total_open' => $match['total_open'],
            'open_invoices' => array_map(
                fn (array $invoice): array => [
                    'invoice_number' => $invoice['invoice_number'],
                    'balance_due' => $invoice['balance_due'],
                    'due_date' => $invoice['due_date'],
                ],
                $match['open_invoices'],
            ),
        ];
    }
}
