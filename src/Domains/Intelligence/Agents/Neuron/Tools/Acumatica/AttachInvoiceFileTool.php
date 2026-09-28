<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\AttachFileToAcumaticaInvoiceAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AttachesFileToDocumentForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPushedInvoiceForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Attaches a file to an already-pushed AR invoice or credit memo, in Kanvas and in Acumatica. */
#[AgentTool(name: 'Attach Invoice File', category: 'accounting')]
class AttachInvoiceFileTool extends Tool
{
    use AttachesFileToDocumentForTool;
    use HasKanvasContext;
    use ResolvesPushedInvoiceForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_invoice_file',
            description: 'Attaches a file to an AR invoice or credit memo that has already been pushed to '
                . 'Acumatica — stores it in Kanvas and uploads it to the Acumatica document too. Identify the '
                . 'file by filesystem_id when someone handed it to you this turn (an attachment marker, '
                . 'download_attachment), or by file_url when all you have is a link.',
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
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of a file already in Kanvas — from an `[Attached file...]` '
                    . 'marker on this turn or from download_attachment. Prefer this over file_url whenever you '
                    . 'have it; pass one of the two.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_url',
                type: PropertyType::STRING,
                description: 'A URL the file can be downloaded from. Use only when you have no filesystem_id.',
                required: false,
            ),
            new ToolProperty(
                name: 'file_name',
                type: PropertyType::STRING,
                description: 'File name to store it under, including extension. Defaults to the file\'s own name.',
                required: false,
            ),
            new ToolProperty(
                name: 'field_name',
                type: PropertyType::STRING,
                description: 'Which slot on the invoice to store the file under. Leave it out only for the '
                    . 'invoice document the customer sent or that the approval flow re-sends. Pass a short name '
                    . 'like "po" or "signed_contract" for any OTHER document, or it replaces that one.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $invoice_id,
        ?int $filesystem_id = null,
        ?string $file_url = null,
        ?string $file_name = null,
        ?string $field_name = null,
    ): array {
        $invoice = $this->resolvePushedInvoice($invoice_id);

        if (is_array($invoice)) {
            return ['file_attached' => false, ...$invoice];
        }

        $file = $this->attachFileToDocument(
            $invoice,
            $filesystem_id,
            $file_url,
            $file_name,
            $field_name,
        );

        if (! isset($file['url'])) {
            return ['file_attached' => false, ...$file];
        }

        $name = $file['name'];

        try {
            new AttachFileToAcumaticaInvoiceAction($invoice, $file['url'], $name)->execute();
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
            'invoice_ref' => (string) $invoice->get(AcumaticaCustomFieldEnum::INVOICE_REF->value, ''),
            'file_name' => $name,
        ];
    }
}
