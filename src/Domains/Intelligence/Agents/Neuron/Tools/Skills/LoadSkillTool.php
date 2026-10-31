<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Skills;

use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillCatalog;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

final class LoadSkillTool extends Tool
{
    protected string $name = 'load_skill';

    protected ?string $description = 'Load the full instructions for one available skill by its exact name. Use only names listed in your skill summary.';

    public function __construct(private readonly SkillCatalog $catalog)
    {
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'skill_name',
                type: PropertyType::STRING,
                description: 'The exact skill name from the available skills summary.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $skill_name): string|array
    {
        if (
            str_contains($skill_name, '..')
            || str_contains($skill_name, '/')
            || str_contains($skill_name, '\\')
            || str_contains($skill_name, "\0")
            || preg_match('/^[a-zA-Z0-9_\-]+$/D', $skill_name) !== 1
        ) {
            return ['status' => 'error', 'message' => 'Invalid skill name. Use an available skill name containing only letters, numbers, underscores, or hyphens.'];
        }

        $skill = $this->catalog->find($skill_name);
        if ($skill === null) {
            return ['status' => 'error', 'message' => 'Skill not found. Use a name from the available skills summary.'];
        }

        $content = @file_get_contents($skill->filePath);
        if ($content === false) {
            return ['status' => 'error', 'message' => 'The requested skill could not be read.'];
        }

        return sprintf(
            "--- START SYSTEM INSTRUCTION MODIFIER: %s ---\n%s\n--- END SYSTEM INSTRUCTION MODIFIER: %s ---",
            $skill->name,
            $content,
            $skill->name,
        );
    }
}
