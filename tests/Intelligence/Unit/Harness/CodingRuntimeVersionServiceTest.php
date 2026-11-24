<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Runtime\ContainerRuntimeDriver;
use Kanvas\Connectors\OpenCode\Services\CodingRuntimeVersionService;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Tests\TestCase;

/**
 * One version for both runtimes, read off the Dockerfile: the container tag and the host binary are
 * two spellings of it, never two numbers.
 */
class CodingRuntimeVersionServiceTest extends TestCase
{
    public function testVersionOfStripsTheRepository(): void
    {
        $this->assertSame('2.2.5', CodingRuntimeVersionService::versionOf('kanvas/opencode:2.2.5'));
        $this->assertSame('2.2.5', CodingRuntimeVersionService::versionOf('2.2.5'));
    }

    public function testTheHostBinaryIsVersioned(): void
    {
        $this->assertSame('/srv/kanvas/bin/opencode-2.2.5', CodingRuntimeVersionService::hostBinary('/srv/kanvas', '2.2.5'));
    }

    public function testReleaseUrlsPointAtTheTaggedRelease(): void
    {
        $base = CodingRuntimeVersionService::RELEASE_BASE . '2.2.5/';

        $this->assertSame($base . CodingRuntimeVersionService::ARCHIVE, CodingRuntimeVersionService::archiveUrl('2.2.5'));
        $this->assertSame($base . CodingRuntimeVersionService::CHECKSUMS, CodingRuntimeVersionService::checksumsUrl('2.2.5'));
    }

    public function testTheUpstreamVersionIsTheDockerfiles(): void
    {
        $dockerfile = (string) file_get_contents(CodingRuntimeVersionService::dockerfilePath());

        $this->assertSame(1, preg_match('/^FROM\s+\S*opencode:(\S+)/mi', $dockerfile, $matches));
        $this->assertSame($matches[1], CodingRuntimeVersionService::upstreamVersion());
    }

    public function testTheContainerDriverComparesTagsAsIs(): void
    {
        $this->assertSame(
            'kanvas/opencode:2.2.5',
            new ContainerRuntimeDriver(new AgentMachine())->versionFor('kanvas/opencode:2.2.5')
        );
    }
}
