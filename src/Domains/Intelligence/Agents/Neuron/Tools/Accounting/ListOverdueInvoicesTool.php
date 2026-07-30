<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Enums\AgingBucketEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Overdue Invoices', category: 'accounting')]
class ListOverdueInvoicesTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'list_overdue_invoices',
            description: 'Lists individual overdue invoices (issued/sent, balance_due > 0, due_date in the past). '
                . 'Returns invoice_number, customer name, total, balance due, days overdue, aging bucket. '
                . 'Use this when the user asks "which invoices are overdue", wants a hit list to chase, or asks '
                . 'about a specific customer\'s late invoices.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max number of invoices to return. Defaults to 25, max 100.',
                required: false,
            ),
            new ToolProperty(
                name: 'min_days_overdue',
                type: PropertyType::INTEGER,
                description: 'Filter to invoices at least N days overdue. Defaults to 1.',
                required: false,
            ),
        ];
    }

    public function __invoke(?int $limit = null, ?int $min_days_overdue = null): array
    {
        $app = $this->app;
        $company = $this->company;
        $limit = max(1, min(100, $limit ?? 25));
        $minDays = max(1, $min_days_overdue ?? 1);
        $today = Carbon::today();

        $invoices = Invoice::query()
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('document_type', DocumentTypeEnum::INVOICE)
            ->whereIn('document_status', [
                InvoiceDocumentStatusEnum::ISSUED,
                InvoiceDocumentStatusEnum::SENT,
            ])
            ->where('balance_due_base', '>', 0.005)
            ->whereDate('due_date', '<=', $today->copy()->subDays($minDays))
            ->where('is_deleted', false)
            ->orderBy('due_date')
            ->limit($limit)
            ->get();

        return [
            'as_of' => $today->toDateString(),
            'count' => $invoices->count(),
            'invoices' => $invoices->map(function (Invoice $inv) use ($today): array {
                $bucket = AgingBucketEnum::forInvoice($inv->due_date, $today);
                $daysOverdue = $inv->due_date !== null
                    ? max(0, (int) $today->diffInDays($inv->due_date, false) * -1)
                    : null;

                return [
                    'id' => (int) $inv->id,
                    'invoice_number' => $inv->invoice_number,
                    'customer_name' => $inv->billable_display_name,
                    'currency' => $inv->currency,
                    'total_native' => (float) $inv->total_native,
                    'balance_due_native' => (float) $inv->balance_due_native,
                    'balance_due_base' => (float) $inv->balance_due_base,
                    'issued_date' => $inv->issued_date?->toDateString(),
                    'due_date' => $inv->due_date?->toDateString(),
                    'days_overdue' => $daysOverdue,
                    'aging_bucket' => $bucket->value,
                ];
            })->all(),
        ];
    }
}
