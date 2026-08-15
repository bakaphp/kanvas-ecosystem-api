<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\HasRunKey;
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
class MatchInvoicesForPaymentTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    private const OPEN_EXCLUDED = ['draft', 'paid', 'voided'];

    public function __construct()
    {
        parent::__construct(
            name: 'match_invoices_for_payment',
            description: 'Given a customer name and a payment amount, lists the customer\'s open (issued, unpaid) '
                . 'invoices and flags an exact single-invoice match. Use this to propose how a received AR payment '
                . 'applies to invoices before recording it. Read-only — proposes, does not apply.',
        );
    }

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
        $limit = max(1, min(100, $limit ?? 25));
        $term = OrganizationNameNormalizerService::normalize($customer) ?: $customer;

        $orgIds = Organization::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('name', 'like', '%' . $term . '%')
            ->pluck('id');

        if ($orgIds->isEmpty()) {
            return ['customer' => $customer, 'amount' => $amount, 'open_invoices' => [], 'reason' => 'No customer matched that name.'];
        }

        $invoices = Invoice::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereIn('customer_organization_id', $orgIds)
            ->whereNotIn('document_status', self::OPEN_EXCLUDED)
            ->where('balance_due_native', '>', 0)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        $exact = $invoices->first(fn (Invoice $i): bool => abs((float) $i->balance_due_native - $amount) < 0.005);

        return [
            'customer' => $customer,
            'amount' => $amount,
            'exact_match' => $exact?->invoice_number,
            'total_open' => round((float) $invoices->sum('balance_due_native'), 2),
            'open_invoices' => $invoices->map(fn (Invoice $i): array => [
                'invoice_number' => $i->invoice_number,
                'balance_due' => round((float) $i->balance_due_native, 2),
                'due_date' => $i->due_date?->toDateString(),
            ])->all(),
        ];
    }
}
