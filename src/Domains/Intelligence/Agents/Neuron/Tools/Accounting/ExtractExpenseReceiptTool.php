<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Traits\ScalarCoercionTrait;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesFilesystemForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Traits\ResolvesPdfClassifierTrait;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Reads a receipt the employee already uploaded and returns the few fields submit_my_expense needs.
 *
 * Shares the classifier with extract_invoice_data — the difference is the shape that comes back, not
 * the reading. An invoice extraction is built around an invoice number, due date and line items; a
 * restaurant or gas-station slip has none of those, so this flattens the payload to merchant / total
 * / date / tax and says plainly when the document does not look like a receipt at all.
 */
#[AgentTool(name: 'Extract Expense Receipt', category: 'accounting')]
class ExtractExpenseReceiptTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use ReportsToolOutcome;
    use ResolvesFilesystemForTool;
    use ResolvesPdfClassifierTrait;
    use ScalarCoercionTrait;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'extract_expense_receipt',
            description: 'Reads a receipt already uploaded to Kanvas (a restaurant bill, hotel folio, taxi or fuel '
                . 'slip, a SaaS charge) and returns the merchant, total, tax, date and currency. Use this before '
                . 'submit_my_expense so the amount comes off the receipt itself rather than from what someone '
                . 'remembers. For a vendor invoice the company still has to pay, use extract_invoice_data instead.',
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
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of the uploaded receipt — from the attachment marker on the '
                    . 'message, or from any other Kanvas file upload.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $filesystem_id): array
    {
        if (! $this->hasTenantContext()) {
            return $this->tenantContextMissingError('receipt');
        }

        $receipt = $this->findTenantFile($filesystem_id);

        if ($receipt === null) {
            return $this->notFound(
                [
                    'success' => false,
                    'reason' => 'file_not_found',
                ],
                guidance: "No file with filesystem_id {$filesystem_id} for this app.",
            );
        }

        try {
            $result = $this->defaultPdfClassifier()->classify($receipt, []);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'Could not read the receipt: ' . $e->getMessage(),
                ['reason' => 'extraction_failed'],
                guidance: 'Ask the person for the amount and date instead of retrying.',
            );
        }

        $extracted = $result->extracted ?? [];
        $total = $this->floatOrNull($extracted['total'] ?? null);

        if ($total === null || $total <= 0) {
            return $this->notFound(
                [
                    'success' => false,
                    'reason' => 'no_total_found',
                    'document_type' => $result->document_type->value,
                ],
                guidance: 'There is no readable total on that file. Ask the person for the amount rather than '
                    . 'guessing it, and do not call this again on the same file.',
            );
        }

        $tax = $this->floatOrNull($extracted['tax'] ?? null) ?? 0.0;

        return $this->ok([
            'document_type' => $result->document_type->value,
            'looks_like_a_receipt' => $result->document_type === PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            'confidence' => $result->confidence,
            'merchant' => $this->stringOrNull($extracted['vendor_name'] ?? null),
            'total' => $total,
            'tax' => $tax,
            'subtotal' => $this->floatOrNull($extracted['subtotal'] ?? null) ?? ($total - $tax),
            'currency' => $this->stringOrNull($extracted['currency'] ?? null) ?? 'USD',
            'expense_date' => $this->stringOrNull($extracted['issue_date'] ?? null),
            'notes' => $this->stringOrNull($extracted['notes'] ?? null),
            'filesystem_id' => $filesystem_id,
        ]);
    }
}
