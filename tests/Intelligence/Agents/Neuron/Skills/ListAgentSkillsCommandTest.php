<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Skills;

use Illuminate\Support\Facades\Artisan;
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
        // One expectsOutputToContain per written chunk: the JSON is a single line() call.
        $this->assertSame(0, Artisan::call('kanvas:agent:skills-list', ['--json' => true]));

        $skills = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertCount(1, $skills);
        $this->assertSame('meeting-notes', $skills[0]['name']);
        $this->assertSame('Summarize meetings', $skills[0]['description']);
    }

    public function testAliasListsSkillsInATable(): void
    {
        $this->artisan('neuron:skills-list')
            ->expectsOutputToContain('Name')
            ->expectsOutputToContain('meeting-notes')
            ->assertExitCode(0);
    }
}
