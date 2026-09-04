<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\AttachFileToAcumaticaBillAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Approvals\Enums\ApprovalAttachmentFieldEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/** Attaches a file to an already-pushed AP bill, in Kanvas and in Acumatica. */
#[AgentTool(name: 'Attach Bill File', category: 'accounting')]
class AttachBillFileTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_bill_file',
            description: 'Attaches a file (by URL) to an AP bill that has already been pushed to Acumatica — '
                . 'stores it in Kanvas and uploads it to the Acumatica document too.',
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
                name: 'bill_id',
                type: PropertyType::INTEGER,
                description: 'The Kanvas bill id to attach the file to (returned as bill_id by create_ap_bill).',
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
    public function __invoke(int $bill_id, string $file_url, ?string $file_name = null): array
    {
        $bill = Bill::query()
            ->where('id', $bill_id)
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        if ($bill === null) {
            return [
                'file_attached' => false,
                'reason' => 'bill_not_found',
                'message' => "No bill with id {$bill_id} for this app/company.",
            ];
        }

        $ref = (string) $bill->get(AcumaticaCustomFieldEnum::BILL_REF->value, '');

        if ($ref === '') {
            return [
                'file_attached' => false,
                'reason' => 'bill_not_pushed',
                'message' => "Bill {$bill_id} hasn't been pushed to Acumatica yet — push it before attaching a file.",
            ];
        }

        $name = $file_name !== null && $file_name !== '' ? $file_name : basename(parse_url($file_url, PHP_URL_PATH) ?: 'file');

        // Same stable field_name create_ap_bill attaches under, so this updates that one row (the
        // source invoice PDF) instead of creating a second, differently-keyed Filesystem attachment.
        $bill->addFileFromUrl($file_url, ApprovalAttachmentFieldEnum::INVOICE_PDF->value);

        try {
            new AttachFileToAcumaticaBillAction($bill, $file_url, $name)->execute();
        } catch (AcumaticaWriteException|Throwable $e) {
            return [
                'file_attached' => true,
                'pushed' => false,
                'bill_id' => $bill->getId(),
                'file_name' => $name,
                'reason' => 'push_failed',
                'message' => 'File saved in Kanvas but the push to Acumatica failed: ' . $e->getMessage(),
            ];
        }

        return [
            'file_attached' => true,
            'pushed' => true,
            'bill_id' => $bill->getId(),
            'bill_ref' => $ref,
            'file_name' => $name,
        ];
    }
}
