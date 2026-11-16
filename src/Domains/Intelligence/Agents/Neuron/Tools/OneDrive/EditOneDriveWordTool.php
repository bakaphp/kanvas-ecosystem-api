<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\Actions\EditWordDocumentAction;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Edit OneDrive Word Document', category: 'productivity')]
class EditOneDriveWordTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_edit_word';

    protected ?string $description = 'Edits a .docx document in the connected OneDrive by replacing text and/or appending '
        . 'paragraphs at the end. Read the document first so the replaced text matches exactly. If the result is '
        . '`file_changed`, the file was modified meanwhile: read the document again before retrying.';

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
                description: 'Path starting with "/" (e.g. "/Documentos/contrato.docx") or an item id.',
                required: true,
            ),
            new ToolProperty(
                name: 'replacements',
                type: PropertyType::STRING,
                description: 'JSON object mapping the text to find to its replacement, e.g. '
                    . '\'{"Fecha: 2025": "Fecha: 2026"}\'.',
                required: false,
            ),
            new ToolProperty(
                name: 'append_paragraphs',
                type: PropertyType::STRING,
                description: 'JSON list of paragraphs to add at the end, e.g. \'["Primer parrafo", "Segundo parrafo"]\'.',
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
        ?string $replacements = null,
        ?string $append_paragraphs = null,
        ?string $drive = null
    ): array {
        return $this->attempt(function () use ($path_or_id, $replacements, $append_paragraphs, $drive) {
            $replacementMap = $this->decodeStringMap($replacements, 'replacements');
            $paragraphs = $this->decodeStringList($append_paragraphs, 'append_paragraphs');

            return new EditWordDocumentAction(
                $this->oneDrive($drive),
                $path_or_id,
                $replacementMap,
                $paragraphs,
            )->execute();
        });
    }
}
