<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Scribe\Quotes\Sow\Actions\SaveCompanySowTemplateAction;
use Kanvas\Scribe\Quotes\Sow\Services\CompanySowTemplateService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Save SOW Template', category: 'accounting')]
class SaveSowTemplateTool extends Tool
{
    use HasKanvasContext;

    protected string $name = 'save_sow_template';

    protected ?string $description = 'Creates or updates one of this company\'s Statement of Work layout versions, in Twig '
        . '({{ sow.summary }}, {% for item in sow.scope %}; no functions or method calls). Start from get_sow_template. '
        . 'It is test-rendered before saving and refused with the reason if it breaks, or if it loads scripts, remote '
        . 'images, stylesheets or fonts (the customer\'s signing page blocks them: use {{ issuer.logo }} and a <style> '
        . 'block). Saving a version does not make it the default unless make_default is true. Editing a version '
        . 'changes the look of unsigned SOWs that use it; signed ones keep their PDF.';

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
                description: 'Version name, e.g. "standard" or "retainer". Saving an existing name updates it.',
                required: true,
            ),
            new ToolProperty(
                name: 'html',
                type: PropertyType::STRING,
                description: 'The full Twig HTML of the layout.',
                required: true,
            ),
            new ToolProperty(
                name: 'make_default',
                type: PropertyType::BOOLEAN,
                description: 'True to make this version the company default for new SOWs.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $version, string $html, ?bool $make_default = null): array
    {
        try {
            $template = new SaveCompanySowTemplateAction(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                version: $version,
                html: $html,
                makeDefault: (bool) $make_default,
            )->execute();
        } catch (ValidationException $e) {
            return ['saved' => false, 'message' => 'Nothing was saved. ' . $e->getMessage()];
        }

        $savedVersion = CompanySowTemplateService::versionOf($template);

        return [
            'saved' => true,
            'version' => $savedVersion,
            'is_default' => new CompanySowTemplateService($this->app, $this->company)->defaultVersion() === $savedVersion,
        ];
    }
}
