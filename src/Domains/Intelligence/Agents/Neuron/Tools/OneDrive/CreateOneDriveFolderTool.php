<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Create OneDrive Folder', category: 'productivity')]
class CreateOneDriveFolderTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_create_folder';

    protected ?string $description = 'Creates a folder in the connected OneDrive. Fails with `name_conflict` if the '
        . 'folder already exists.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Name of the new folder.',
                required: true,
            ),
            new ToolProperty(
                name: 'parent',
                type: PropertyType::STRING,
                description: 'Parent folder path starting with "/" (e.g. "/Documentos") or a folder id. Omit for the root.',
                required: false,
            ),
            $this->driveProperty(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $name, ?string $parent = null, ?string $drive = null): array
    {
        return $this->attempt(fn () => ['item' => $this->oneDrive($drive)->createFolder($parent, $name)]);
    }
}
