<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Skills\Services;

use Kanvas\Intelligence\Agents\Neuron\Skills\DataTransferObject\SkillDefinition;

final class SkillCatalog
{
    /** @var list<array{path: string, source: string}> */
    private readonly array $roots;

    /** @var array<string, SkillDefinition>|null */
    private ?array $skills = null;

    /**
     * @param list<array{path: string, source: string}>|null $roots
     */
    public function __construct(
        private readonly SkillFrontmatterParser $parser,
        ?array $roots = null,
    ) {
        $this->roots = $roots ?? [
            ['path' => base_path('vendor/neuron-core/neuron-ai/skills'), 'source' => 'vendor'],
            ['path' => base_path('.agents/skills'), 'source' => '.agents'],
            ['path' => resource_path('agents/skills'), 'source' => 'resources'],
        ];
    }

    /**
     * @return list<SkillDefinition>
     */
    public function getSkills(): array
    {
        return array_values($this->discover());
    }

    public function find(string $name): ?SkillDefinition
    {
        return $this->discover()[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return $this->find($name) !== null;
    }

    public function getPromptSummary(): string
    {
        $skills = $this->getSkills();
        if ($skills === []) {
            return '';
        }

        $lines = ['Available skills (call load_skill with a skill name to load its instructions):'];
        foreach ($skills as $skill) {
            $lines[] = sprintf('- %s: %s', $skill->name, $skill->description);
        }

        return implode("\n", $lines);
    }

    /** @return array<string, SkillDefinition> */
    private function discover(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $skills = [];
        foreach ($this->roots as $root) {
            if (! is_dir($root['path'])) {
                continue;
            }

            foreach (glob($root['path'] . '/*/SKILL.md') ?: [] as $filePath) {
                if (! is_file($filePath)) {
                    continue;
                }

                $definition = $this->parser->parse($filePath, $root['source']);
                // Later roots have higher precedence, so resources override .agents and vendor.
                $skills[$definition->name] = $definition;
            }
        }

        ksort($skills);

        return $this->skills = $skills;
    }
}
