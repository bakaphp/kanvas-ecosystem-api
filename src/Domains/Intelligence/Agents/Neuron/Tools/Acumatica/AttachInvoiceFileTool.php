<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\AttachFileToAcumaticaInvoiceAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Attaches a file to an already-pushed AR invoice or credit memo, in Kanvas and in Acumatica. */
#[AgentTool(name: 'Attach Invoice File', category: 'accounting')]
class AttachInvoiceFileTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_invoice_file',
            description: 'Attaches a file (by URL) to an AR invoice or credit memo that has already been pushed '
                . 'to Acumatica — stores it in Kanvas and uploads it to the Acumatica document too.',
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
                name: 'invoice_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas invoice/credit memo id to attach the file to (returned as invoice_id by '
                    . 'create_ar_invoice, or credit_memo_id by create_ar_credit_memo).',
                required: true,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A URL the file can be downloaded from.',
                required: true,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'File name to store it under, including extension. Defaults to the URL\'s own file name.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $invoice_id, string $file_url, ?string $file_name = null): array
    {
        $invoice = Invoice::query()
            ->where('id', $invoice_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($invoice === null) {
            return [
                'file_attached' => false,
                'reason' => 'invoice_not_found',
                'message' => "No invoice with id {$invoice_id} for this app/company.",
            ];
        }

        $ref = (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, '');

        if ($ref === '') {
            return [
                'file_attached' => false,
                'reason' => 'invoice_not_pushed',
                'message' => "Invoice {$invoice_id} hasn't been pushed to Acumatica yet — push it before attaching a file.",
            ];
        }

        $name = $file_name !== null && $file_name !== '' ? $file_name : basename(parse_url($file_url, PHP_URL_PATH) ?: 'file');

        $invoice->addFileFromUrl($file_url, $name);

        try {
            new AttachFileToAcumaticaInvoiceAction($invoice, $file_url, $name)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'file_attached' => true,
                'pushed' => false,
                'invoice_id' => $invoice->getId(),
                'file_name' => $name,
                'reason' => 'push_failed',
                'message' => 'File saved in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'file_attached' => true,
            'pushed' => true,
            'invoice_id' => $invoice->getId(),
            'invoice_ref' => $ref,
            'file_name' => $name,
        ];
    }
}
