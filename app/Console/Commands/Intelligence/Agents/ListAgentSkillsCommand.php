<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Agents;

use Illuminate\Console\Command;
use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillCatalog;

final class ListAgentSkillsCommand extends Command
{
    protected $signature = 'kanvas:agent:skills-list {--json : Output as JSON}';

    protected $description = 'List the custom skills available to Neuron agents.';

    protected $aliases = ['neuron:skills-list'];

    public function handle(SkillCatalog $catalog): int
    {
        $skills = $catalog->getSkills();
        if ($this->option('json')) {
            $this->line(json_encode($skills, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Description', 'Source', 'Path'],
            array_map(
                static fn ($skill): array => [$skill->name, $skill->description, $skill->source, $skill->filePath],
                $skills,
            ),
        );

        return self::SUCCESS;
    }
}
