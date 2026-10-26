<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Skills;

use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillFrontmatterParser;
use Symfony\Component\Filesystem\Filesystem;
use Tests\TestCase;

final class SkillFrontmatterParserTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/kanvas-skills-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->directory);
        parent::tearDown();
    }

    public function testParsesOnlyTheYamlMetadataAndKeepsTheFileReference(): void
    {
        $filePath = $this->writeSkill("---\nname: meeting-notes\ndescription: Summarize meetings\n---\n" . str_repeat('body', 100));

        $definition = (new SkillFrontmatterParser())->parse($filePath, 'resources');

        $this->assertSame('meeting-notes', $definition->name);
        $this->assertSame('Summarize meetings', $definition->description);
        $this->assertSame($filePath, $definition->filePath);
        $this->assertSame('resources', $definition->source);
    }

    public function testMissingDelimiterFallsBackToDirectoryNameAndBlankDescription(): void
    {
        $filePath = $this->writeSkill("name: ignored\ndescription: ignored\nbody");

        $definition = (new SkillFrontmatterParser())->parse($filePath);

        $this->assertSame('custom-skill', $definition->name);
        $this->assertSame('', $definition->description);
    }

    private function writeSkill(string $content): string
    {
        $skillDirectory = $this->directory . '/custom-skill';
        mkdir($skillDirectory, 0777, true);
        $filePath = $skillDirectory . '/SKILL.md';
        file_put_contents($filePath, $content);

        return $filePath;
    }
}
