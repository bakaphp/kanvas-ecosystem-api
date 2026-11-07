<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron\Skills;

use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillCatalog;
use Kanvas\Intelligence\Agents\Neuron\Skills\Services\SkillFrontmatterParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class SkillCatalogTest extends TestCase
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

    public function testLaterRootsOverrideEarlierDefinitionsWithTheSameSkillName(): void
    {
        $vendorRoot = $this->directory . '/vendor';
        $projectRoot = $this->directory . '/project';
        $resourceRoot = $this->directory . '/resources';
        $this->writeSkill($vendorRoot, 'first-folder', 'format-docs', 'Vendor description');
        $this->writeSkill($projectRoot, 'second-folder', 'format-docs', 'Project description');
        $resourcePath = $this->writeSkill($resourceRoot, 'third-folder', 'format-docs', 'Resource description');

        $catalog = new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [
                ['path' => $vendorRoot, 'source' => 'vendor'],
                ['path' => $projectRoot, 'source' => '.agents'],
                ['path' => $resourceRoot, 'source' => 'resources'],
                ['path' => $this->directory . '/missing', 'source' => 'missing'],
            ],
        );

        $this->assertCount(1, $catalog->getSkills());
        $this->assertSame($resourcePath, $catalog->find('format-docs')?->filePath);
        $this->assertSame('resources', $catalog->find('format-docs')?->source);
        $this->assertTrue($catalog->has('format-docs'));
        $this->assertFalse($catalog->has('missing'));
        $this->assertStringContainsString('- format-docs: Resource description', $catalog->getPromptSummary());
    }

    public function testDefinitionsAreMemoizedAfterFirstDiscovery(): void
    {
        $root = $this->directory . '/skills';
        $this->writeSkill($root, 'alpha', 'alpha', 'Original');
        $catalog = new SkillCatalog(
            parser: new SkillFrontmatterParser(),
            roots: [['path' => $root, 'source' => 'test']],
        );

        $this->assertSame('Original', $catalog->find('alpha')?->description);
        $this->writeSkill($root, 'alpha', 'alpha', 'Changed');
        $this->assertSame('Original', $catalog->find('alpha')?->description);
    }

    private function writeSkill(string $root, string $folder, string $name, string $description): string
    {
        $skillDirectory = $root . '/' . $folder;
        if (! is_dir($skillDirectory)) {
            mkdir($skillDirectory, 0777, true);
        }
        $filePath = $skillDirectory . '/SKILL.md';
        file_put_contents($filePath, sprintf("---\nname: %s\ndescription: %s\n---\nBody\n", $name, $description));

        return $filePath;
    }
}
