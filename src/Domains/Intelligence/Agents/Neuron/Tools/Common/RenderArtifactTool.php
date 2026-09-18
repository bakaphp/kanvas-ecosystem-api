<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Common;

use Kanvas\Intelligence\Agents\Enums\ArtifactComponentEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Services\ArtifactBlockService;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * Only handed to an agent on a surface that renders `kanvas-artifact` blocks (the admin userChat) —
 * anywhere else the block reaches the reader as raw JSON. No #[AgentTool] for the same reason: a
 * catalog grant would put it on Slack and connector surfaces.
 */
class RenderArtifactTool extends Tool
{
    use DecodesJsonObjectParam;
    use ReportsToolOutcome;

    public function __construct()
    {
        parent::__construct(
            name: 'render_artifact',
            description: self::describe(),
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'component',
                type: PropertyType::STRING,
                description: 'Which component to render.',
                required: true,
                enum: ArtifactComponentEnum::values(),
            ),
            new ToolProperty(
                name: 'props',
                type: PropertyType::STRING,
                description: 'The component props as a JSON-encoded object, with the exact prop names listed '
                    . 'in the tool description.',
                required: true,
            ),
            new ToolProperty(
                name: 'title',
                type: PropertyType::STRING,
                description: 'Short title shown above the component.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $component, array|string|null $props = null, ?string $title = null): array
    {
        $resolved = ArtifactComponentEnum::tryFrom(trim($component));

        if ($resolved === null) {
            return $this->invalidArgs(sprintf(
                'Unknown component "%s". Use one of: %s.',
                $component,
                implode(', ', ArtifactComponentEnum::values())
            ));
        }

        if (is_string($props) && trim($props) !== '' && ! is_array(json_decode($props, true))) {
            return $this->invalidArgs('props is not a valid JSON object.');
        }

        $decoded = $this->decodeJsonObjectParam($props);
        $service = new ArtifactBlockService();
        $errors = $service->errors($resolved, $decoded);

        if ($errors !== []) {
            return $this->invalidArgs(implode('; ', $errors));
        }

        return $this->ok(
            ['block' => $service->render($resolved, $title, $decoded)],
            'Paste the block into your reply exactly as returned, fences included, where it belongs in the answer.'
        );
    }

    private static function describe(): string
    {
        $components = implode("\n", array_map(
            static fn (ArtifactComponentEnum $component): string => '- ' . $component->usage(),
            ArtifactComponentEnum::cases()
        ));

        return 'Render structured data as a visual component in this chat. Whenever your reply contains a '
            . 'balance, a record, a list, a metric or a sequence, call this and paste the returned block into your '
            . 'reply verbatim — visual by default, without being asked. This overrides a plain-text output '
            . 'rule for that data. Use only real values your tools returned; never invent or pad numbers. Keep prose '
            . 'around blocks short. Several blocks per reply are fine. Confirmations of a completed action are prose '
            . 'first, then at most one block. Never use it for source code. If nothing fits, plain markdown is fine.'
            . "\nComponents:\n" . $components;
    }
}
