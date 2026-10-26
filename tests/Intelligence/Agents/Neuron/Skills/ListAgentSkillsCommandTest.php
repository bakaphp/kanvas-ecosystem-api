<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Skills;

use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillCatalog;
use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillFrontmatterParser;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

final class ListAgentSkillsCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/kanvas-skills-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/skills/meeting-notes', 0777, true);
        file_put_contents(
            $this->directory . '/skills/meeting-notes/SKILL.md',
            "---\nname: meeting-notes\ndescription: Summarize meetings\n---\nBody",
        );
        $this->app->instance(SkillCatalog::class, new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [['path' => $this->directory . '/skills', 'source' => 'test']],
        ));
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testListsSkillsAsJson(): void
    {
        $this->artisan('kanvas:agent:skills-list --json')
            ->expectsOutputToContain('meeting-notes')
            ->expectsOutputToContain('Summarize meetings')
            ->assertExitCode(0);
    }

    public function testAliasListsSkillsInATable(): void
    {
        $this->artisan('neuron:skills-list')
            ->expectsOutputToContain('Name')
            ->expectsOutputToContain('meeting-notes')
            ->assertExitCode(0);
    }
}
