<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive;

use Kanvas\Connectors\Microsoft\OneDrive\OneDriveClient;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Concerns\CallsOneDrive;
use Kanvas\Intelligence\Agents\Neuron\Tools\OneDrive\Exceptions\InvalidToolInputException;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Delete OneDrive Item', category: 'productivity')]
class DeleteOneDriveItemTool extends Tool
{
    use CallsOneDrive;
    use HasKanvasContext;

    protected string $name = 'onedrive_delete_item';

    protected ?string $description = 'Moves a file or folder in the connected OneDrive to its recycle bin. Only use this '
        . 'when the user explicitly asked to delete that item, and confirm the exact name with them first.';

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
                description: 'Path starting with "/" (e.g. "/Documentos/viejo.docx") or an item id.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $path_or_id): array
    {
        return $this->attempt(function () use ($path_or_id) {
            if (OneDriveClient::isRootPath($path_or_id)) {
                throw new InvalidToolInputException('path_or_id must name a specific file or folder, not the root.');
            }

            $this->oneDrive()->delete($path_or_id);

            return ['deleted' => $path_or_id];
        });
    }
}
