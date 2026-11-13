<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Set Default SOW Template', category: 'accounting')]
class SetDefaultSowTemplateTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'set_default_sow_template';

    protected ?string $description = 'Switches which of this company\'s SOW layout versions new SOWs use. Omit version to go '
        . 'back to the stock layout. Only SOWs written after the switch change; every SOW already written keeps the '
        . 'layout it was written with.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'version',
                type: PropertyType::STRING,
                description: 'An existing version name (from get_sow_template). Omit for the stock layout.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $version = null): array
    {
        try {
            $normalized = $version !== null ? CompanySowTemplateService::normalizeVersion($version) : null;
            new CompanySowTemplateService($this->app, $this->company)->setDefault($normalized);
        } catch (ValidationException $e) {
            return ['switched' => false, 'message' => $e->getMessage()];
        }

        return ['switched' => true, 'default_version' => $normalized, 'is_stock' => $normalized === null];
    }
}
