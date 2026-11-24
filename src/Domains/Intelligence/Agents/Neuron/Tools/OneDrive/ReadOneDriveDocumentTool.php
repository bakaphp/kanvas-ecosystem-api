<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\ReadOneDriveDocumentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Read OneDrive Document', category: 'productivity')]
class ReadOneDriveDocumentTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_read_document';

    protected ?string $description = 'Reads a document from the connected OneDrive and returns its content. '
        . 'Can read .docx, .xlsx, .txt, .csv and .md files. Word and text files return text (truncated when very long); '
        . 'Excel files return rows of cells, at most 500 rows and 50 columns per read (`truncated` is true when more '
        . 'exist: read a narrower range). Excel dates come back as serial numbers (e.g. 45000), not formatted dates.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'path_or_id',
                type: PropertyType::STRING,
                description: 'Path starting with "/" (e.g. "/Documentos/contrato.docx") or an item id returned by '
                    . 'search/list.',
                required: true,
            ),
            new ToolProperty(
                name: 'sheet',
                type: PropertyType::STRING,
                description: 'Excel only: sheet name to read. Defaults to the first sheet.',
                required: false,
            ),
            new ToolProperty(
                name: 'range',
                type: PropertyType::STRING,
                description: 'Excel only: A1 range to read, e.g. "A1:D20". Defaults to the whole sheet.',
                required: false,
            ),
            $this->driveProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $path_or_id,
        ?string $sheet = null,
        ?string $range = null,
        ?string $drive = null
    ): array {
        return $this->attempt(fn () => new ReadOneDriveDocumentAction(
            $this->oneDrive($drive),
            $path_or_id,
            $sheet,
            $range,
        )->execute());
    }
}
