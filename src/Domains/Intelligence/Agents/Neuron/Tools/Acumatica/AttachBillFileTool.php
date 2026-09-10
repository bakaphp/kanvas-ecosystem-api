<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Acumatica;

use Kanvas\Connectors\Acumatica\Actions\AttachFileToAcumaticaBillAction;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum as AcumaticaCustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\AttachesFileToDocumentForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
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
    use AttachesFileToDocumentForTool;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'attach_bill_file',
            description: 'Attaches a file to an AP bill that has already been pushed to Acumatica — stores it '
                . 'in Kanvas and uploads it to the Acumatica document too. Identify the file by filesystem_id '
                . 'when someone handed it to you this turn (an attachment marker, download_attachment), or by '
                . 'file_url when all you have is a link.',
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
                name: 'filesystem_id',
                type: PropertyType::INTEGER,
                description: 'The filesystem_id of a file already in Kanvas — from an `[Attached file...]` '
                    . 'marker on this turn, download_attachment, or create_ap_bill. Prefer this over file_url '
                    . 'whenever you have it; pass one of the two.',
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
                description: 'Which slot on the bill to store the file under. Leave it out for the bill\'s source '
                    . 'invoice PDF (the one the approval flow re-sends). Pass a short name like "w9" or '
                    . '"remittance" for any OTHER document, or it replaces the invoice PDF already on file.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $bill_id,
        ?int $filesystem_id = null,
        ?string $file_url = null,
        ?string $file_name = null,
        ?string $field_name = null,
    ): array {
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

        $file = $this->attachFileToDocument(
            $bill,
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
            new AttachFileToAcumaticaBillAction($bill, $file['url'], $name)->execute();
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
