<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Skills;

use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillCatalog;
use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillFrontmatterParser;
use Kanvas\Intelligence\Agents\Neuron\Tools\Skills\LoadSkillTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class LoadSkillToolTest extends TestCase
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

    public function testLoadsSkillContentsInsideInstructionModifierMarkers(): void
    {
        $root = $this->directory . '/skills';
        $skillDirectory = $root . '/documenting';
        mkdir($skillDirectory, 0777, true);
        file_put_contents($skillDirectory . '/SKILL.md', "---\nname: documenting\ndescription: Write docs\n---\nWrite concise docs.");
        $tool = new LoadSkillTool(new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [['path' => $root, 'source' => 'test']],
        ));

        $result = $tool('documenting');

        $this->assertIsString($result);
        $this->assertStringStartsWith('--- START SYSTEM INSTRUCTION MODIFIER: documenting ---', $result);
        $this->assertStringContainsString('Write concise docs.', $result);
        $this->assertStringEndsWith('--- END SYSTEM INSTRUCTION MODIFIER: documenting ---', $result);
    }

    public function testRejectsTraversalAndInvalidSkillNames(): void
    {
        $tool = new LoadSkillTool(new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [],
        ));

        foreach (['../secret', 'nested/skill', 'nested\\skill', "bad\0name", 'has space', "trailing-newline\n"] as $name) {
            $result = $tool($name);
            $this->assertIsArray($result);
            $this->assertSame('error', $result['status']);
        }
    }

    public function testUnknownNameReturnsAnErrorWithoutAttemptingToReadAFile(): void
    {
        $tool = new LoadSkillTool(new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [],
        ));

        $result = $tool('not-found');

        $this->assertIsArray($result);
        $this->assertSame('error', $result['status']);
    }
}
