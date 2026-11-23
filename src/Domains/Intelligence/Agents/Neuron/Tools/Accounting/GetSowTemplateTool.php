<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use Kanvas\Scribe\Quotes\Sow\Support\SampleSowContext;
use Kanvas\Templates\Models\Templates;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Get SOW Template', category: 'accounting')]
class GetSowTemplateTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'get_sow_template';

    protected ?string $description = 'Reads this company\'s Statement of Work layout (Twig): the versions it has, which one is '
        . 'the default, and the Twig of the version you name (or of the layout in effect when you name none: the '
        . 'default, else the stock layout). Also returns every variable a SOW template receives, with sample values. '
        . 'Always start an edit from this, then save it with save_sow_template.';

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
                description: 'Optional version name, e.g. "retainer". Omit for the layout in effect.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $version = null): array
    {
        $templates = new CompanySowTemplateService($this->app, $this->company);

        try {
            $normalized = $version !== null ? CompanySowTemplateService::normalizeVersion($version) : null;
            $source = $templates->source($normalized);
        } catch (ValidationException $e) {
            return ['found' => false, 'message' => $e->getMessage()];
        }

        $default = $templates->defaultVersion();

        return [
            'found' => true,
            'version' => $normalized ?? $default,
            'is_stock' => $normalized === null && $default === null,
            'default_version' => $default,
            'versions' => array_map(
                static fn (Templates $template): array => [
                    'version' => CompanySowTemplateService::versionOf($template),
                    'updated_at' => $template->updated_at?->toDateTimeString(),
                ],
                $templates->versions(),
            ),
            'twig' => $source,
            'variables' => SampleSowContext::get(),
        ];
    }
}
