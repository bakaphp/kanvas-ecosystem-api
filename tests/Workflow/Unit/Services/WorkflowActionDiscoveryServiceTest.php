<?php

declare(strict_types=1);

namespace Tests\Workflow\Unit\Services;

use Kanvas\Workflow\Services\WorkflowActionDiscoveryService;
use Tests\TestCase;
use Tests\Workflow\Unit\Services\Fixtures\AbstractTaggedFixture;
use Tests\Workflow\Unit\Services\Fixtures\TaggedActivityFixture;
use Tests\Workflow\Unit\Services\Fixtures\TaggedWithCustomNameFixture;
use Tests\Workflow\Unit\Services\Fixtures\UntaggedFixture;

final class WorkflowActionDiscoveryServiceTest extends TestCase
{
    private function discover(): array
    {
        $service = new WorkflowActionDiscoveryService([__DIR__ . '/Fixtures']);

        return $service->discover();
    }

    public function testDiscoversTaggedClass(): void
    {
        $classes = array_column($this->discover(), 'class');

        $this->assertContains(TaggedActivityFixture::class, $classes);
    }

    public function testSkipsUntaggedClass(): void
    {
        $classes = array_column($this->discover(), 'class');

        $this->assertNotContains(UntaggedFixture::class, $classes);
    }

    public function testSkipsAbstractTaggedClass(): void
    {
        $classes = array_column($this->discover(), 'class');

        $this->assertNotContains(AbstractTaggedFixture::class, $classes);
    }

    public function testDefaultsNameToClassBasename(): void
    {
        $entry = $this->entryFor(TaggedActivityFixture::class);

        $this->assertSame('TaggedActivityFixture', $entry['name']);
        $this->assertNull($entry['description']);
    }

    public function testHonorsNameOverrideFromAttribute(): void
    {
        $entry = $this->entryFor(TaggedWithCustomNameFixture::class);

        $this->assertSame('Custom Display Name', $entry['name']);
    }

    private function entryFor(string $class): array
    {
        foreach ($this->discover() as $entry) {
            if ($entry['class'] === $class) {
                return $entry;
            }
        }

        $this->fail("Class {$class} was not discovered");
    }
}
