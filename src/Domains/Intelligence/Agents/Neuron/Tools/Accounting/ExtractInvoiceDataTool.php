<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Reads a PDF already saved in Kanvas (e.g. via download_attachment) and extracts vendor/total/dates/line items with AI — the real invoice numbers don't live in an email body. */
#[AgentTool(name: 'Extract Invoice Data', category: 'accounting')]
class ExtractInvoiceDataTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'extract_invoice_data',
            description: 'Reads a PDF already stored in Kanvas (e.g. the filesystem_id returned by '
                . 'download_attachment) and uses AI to classify it and extract vendor name, total, currency, '
                . 'dates, and line items. Use this before writing an invoice\'s amount anywhere — never guess a '
                . 'total from an email\'s subject/body text alone; the real figures are inside the PDF.',
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
                description: 'The filesystem_id returned by download_attachment (or any other Kanvas file upload). '
                    . 'Always required.',
                required: true,
            ),
            new ToolProperty(
                name: 'from_email',
                type: PropertyType::STRING,
                description: 'The sender email from read_email_details, if known — helps identify the vendor.',
                required: false,
            ),
            new ToolProperty(
                name: 'subject',
                type: PropertyType::STRING,
                description: 'The email subject from read_email_details, if known.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $filesystem_id, ?string $from_email = null, ?string $subject = null): array
    {
        $pdf = Filesystem::query()
            ->where('id', $filesystem_id)
            ->where('apps_id', $this->app->getId())
            ->first();

        if ($pdf === null) {
            return [
                'success' => false,
                'reason' => 'file_not_found',
                'message' => "No file with filesystem_id {$filesystem_id} for this app.",
            ];
        }

        try {
            $result = $this->classifier()->classify($pdf, [
                'from_email' => $from_email,
                'subject' => $subject,
            ]);
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => 'classification_failed',
                'message' => 'Could not read the PDF: ' . $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'document_type' => $result->document_type->value,
            'confidence' => $result->confidence,
            'reasoning' => $result->reasoning,
            'extracted' => $result->extracted,
        ];
    }

    private function classifier(): PdfClassifierServiceInterface
    {
        return app()->bound(PdfClassifierServiceInterface::class)
            ? app(PdfClassifierServiceInterface::class)
            : new GeminiPdfClassifierService();
    }
}
