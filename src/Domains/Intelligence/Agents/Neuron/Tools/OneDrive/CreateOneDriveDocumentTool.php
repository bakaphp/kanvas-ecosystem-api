<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\CreateOneDriveDocumentAction;
use Kanvas\Connectors\Microsoft\OneDrive\Enums\OneDriveDocumentTypeEnum;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Exceptions\InvalidToolInputException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Create OneDrive Document', category: 'productivity')]
class CreateOneDriveDocumentTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_create_document';

    protected ?string $description = 'Creates a new Word (.docx) or Excel (.xlsx) document in the connected OneDrive. '
        . 'Fails with `name_conflict` if a file with that name already exists in the folder; it never overwrites.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'type',
                type: PropertyType::STRING,
                description: 'Document type: "word" or "excel".',
                required: true,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'File name, e.g. "Informe mensual". The extension is added when missing.',
                required: true,
            ),
            new ToolProperty(
                name: 'folder',
                type: PropertyType::STRING,
                description: 'Destination folder path starting with "/" or a folder id. Omit for the root.',
                required: false,
            ),
            new ToolProperty(
                name: 'content',
                type: PropertyType::STRING,
                description: 'JSON initial content. Word: list of paragraphs, e.g. \'["Titulo", "Texto"]\'. '
                    . 'Excel: list of rows, e.g. \'[["Nombre", "Monto"], ["Ana", 500]]\'.',
                required: false,
            ),
            $this->driveProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        string $type,
        string $name,
        ?string $folder = null,
        ?string $content = null,
        ?string $drive = null
    ): array {
        return $this->attempt(function () use ($type, $name, $folder, $content, $drive) {
            $documentType = OneDriveDocumentTypeEnum::tryFrom(strtolower(trim($type)))
                ?? throw new InvalidToolInputException('type must be "word" or "excel".');

            $initial = $documentType === OneDriveDocumentTypeEnum::WORD
                ? $this->decodeStringList($content, 'content')
                : $this->decodeRows($content, 'content');

            return new CreateOneDriveDocumentAction(
                $this->oneDrive($drive),
                $documentType,
                $name,
                $folder,
                $initial,
            )->execute();
        });
    }
}
