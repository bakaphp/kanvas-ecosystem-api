<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Lists open (received, unpaid) bills — what we currently owe, invoice by invoice. Optionally
 * filtered to a vendor or to only past-due bills.
 */
#[AgentTool(name: 'List Open Bills')]
class ListOpenBillsTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'list_open_bills',
            description: 'Lists individual open bills (received, balance_due > 0) with vendor, bill number, total, '
                . 'balance due, due date and days overdue. Use this when the user asks what we owe, which bills '
                . 'are outstanding or due soon, or wants a specific vendor\'s unpaid bills. Set only_overdue to '
                . 'focus on past-due obligations.',
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
                name: 'vendor',
                type: PropertyType::STRING,
                description: 'Filter to a vendor by (partial) display name. Omit for all vendors.',
                required: false,
            ),
            new ToolProperty(
                name: 'only_overdue',
                type: PropertyType::BOOLEAN,
                description: 'When true, only bills past their due date. Defaults to false.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max bills to return. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $vendor = null, ?bool $only_overdue = null, ?int $limit = null): array
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $limit = max(1, min(100, $limit ?? 25));
        $today = Carbon::today();

        $bills = Bill::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('document_status', BillDocumentStatusEnum::RECEIVED->value)
            ->where('balance_due_native', '>', 0.005)
            ->where('is_deleted', false)
            ->when($vendor !== null && $vendor !== '', fn ($q) => $q->where('vendor_display_name', 'like', '%' . $vendor . '%'))
            ->when($only_overdue === true, fn ($q) => $q->whereDate('due_date', '<', $today))
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        return [
            'as_of' => $today->toDateString(),
            'count' => $bills->count(),
            'total_balance_due' => round((float) $bills->sum('balance_due_native'), 2),
            'bills' => $bills->map(function (Bill $bill) use ($today): array {
                $daysOverdue = $bill->due_date !== null && $bill->due_date->isBefore($today)
                    ? (int) $bill->due_date->diffInDays($today)
                    : 0;

                return [
                    'bill_number' => $bill->bill_number,
                    'vendor' => $bill->vendor_display_name,
                    'currency' => $bill->currency,
                    'total_native' => (float) $bill->total_native,
                    'balance_due_native' => (float) $bill->balance_due_native,
                    'due_date' => $bill->due_date?->toDateString(),
                    'days_overdue' => $daysOverdue,
                ];
            })->all(),
        ];
    }
}
