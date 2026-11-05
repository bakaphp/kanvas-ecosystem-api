<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Search OneDrive', category: 'productivity')]
class SearchOneDriveTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;

    protected string $name = 'onedrive_search';

    protected ?string $description = 'Searches the connected OneDrive for files and folders by name or content, '
        . 'optionally only inside one folder and its subfolders. '
        . 'Returns each match with its id, name, path, type and size; use the id or path with the other OneDrive tools. '
        . 'OneDrive indexes new or just-edited files with a delay, so a recent file may not be found yet: '
        . 'if you know roughly where it is, use onedrive_list_folder instead.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'query',
                type: PropertyType::STRING,
                description: 'Text to search for, e.g. "contrato" or "presupuesto 2025".',
                required: true,
            ),
            new ToolProperty(
                name: 'folder',
                type: PropertyType::STRING,
                description: 'Only return matches inside this folder and its subfolders: a path starting with "/" '
                    . '(e.g. "/Documentos/Legal") or a folder id. Omit to search the whole OneDrive.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?string $folder = null): array
    {
        return $this->attempt(function () use ($query, $folder) {
            $items = $this->oneDrive()->search($query, folder: $folder);

            return ['count' => count($items), 'items' => $items];
        });
    }
}
