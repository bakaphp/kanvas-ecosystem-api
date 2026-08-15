<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * The AP cash-matching helper (UC3): given a vendor and a cleared-payment amount, lists that vendor's
 * OPEN bills and flags how the payment likely applies — an exact single-bill match, or the set of
 * open bills. The agent uses this to PROPOSE the application; a human confirms before anything posts.
 * Read-only.
 */
#[AgentTool(name: 'Match Bills For Payment', category: 'accounting')]
class MatchBillsForPaymentTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'match_bills_for_payment',
            description: 'Given a vendor name and a payment amount, lists the vendor\'s open (received, unpaid) '
                . 'bills and flags an exact single-bill match. Use this to propose how a cleared AP payment '
                . 'applies to bills before recording it. Read-only — proposes, does not apply.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'vendor', type: PropertyType::STRING, description: 'Vendor name (as on the payment/remittance).', required: true),
            new ToolProperty(name: 'amount', type: PropertyType::NUMBER, description: 'The payment amount to match.', required: true),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max bills to return. Default 25.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $vendor, float $amount, ?int $limit = null): array
    {
        $limit = max(1, min(100, $limit ?? 25));
        $term = OrganizationNameNormalizerService::normalize($vendor) ?: $vendor;

        $orgIds = Organization::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->where('name', 'like', '%' . $term . '%')
            ->pluck('id');

        if ($orgIds->isEmpty()) {
            return ['vendor' => $vendor, 'amount' => $amount, 'open_bills' => [], 'reason' => 'No vendor matched that name.'];
        }

        $bills = Bill::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_deleted', false)
            ->whereIn('vendor_organization_id', $orgIds)
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        $exact = $bills->first(fn (Bill $b): bool => abs((float) $b->balance_due_native - $amount) < 0.005);

        return [
            'vendor' => $vendor,
            'amount' => $amount,
            'exact_match' => $exact?->bill_number,
            'total_open' => round((float) $bills->sum('balance_due_native'), 2),
            'open_bills' => $bills->map(fn (Bill $b): array => [
                'bill_number' => $b->bill_number,
                'balance_due' => round((float) $b->balance_due_native, 2),
                'due_date' => $b->due_date?->toDateString(),
            ])->all(),
        ];
    }
}
