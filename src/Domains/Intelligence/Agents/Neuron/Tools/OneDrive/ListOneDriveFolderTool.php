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

#[AgentTool(name: 'List OneDrive Folder', category: 'productivity')]
class ListOneDriveFolderTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;
    use TrackByInputs;

    protected string $name = 'onedrive_list_folder';

    protected ?string $description = 'Lists the files and folders inside a folder of the connected OneDrive. '
        . 'Omit the folder to list the root of the drive.';

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
                description: 'Folder path starting with "/" (e.g. "/Documentos/Contratos") or a folder id returned by '
                    . 'search/list. Omit for the root.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $path_or_id = null): array
    {
        return $this->attempt(function () use ($path_or_id) {
            $items = $this->oneDrive()->listFolder($path_or_id);

            return ['count' => count($items), 'items' => $items];
        });
    }
}
