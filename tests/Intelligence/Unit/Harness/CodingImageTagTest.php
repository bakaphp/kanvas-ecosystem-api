<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Connectors\OpenCode\Services\CodingImageService;
use Tests\TestCase;

/**
 * The image tag follows the Dockerfile, so nobody types a version.
 *
 * It was hardcoded in three places and two still said `1.18.32` after the Dockerfile moved to 2.0.16 —
 * a version this connector cannot talk to, handed to every new install as the default. The tag and the
 * thing that builds it have to come from one place or they disagree silently, and the disagreement only
 * shows up as a container running the wrong opencode.
 */
class CodingImageTagTest extends TestCase
{
    public function testTheTagMatchesTheVersionTheDockerfileBuilds(): void
    {
        $dockerfile = (string) file_get_contents(CodingImageService::dockerfilePath());

        $this->assertSame(
            1,
            preg_match('/^FROM\s+\S*opencode:(\S+)/mi', $dockerfile, $matches),
            'The Dockerfile no longer declares an opencode base image; the tag cannot be derived.'
        );

        $this->assertSame('kanvas/opencode:' . $matches[1], CodingImageService::pinnedTag());
    }

    /**
     * A floating tag would reintroduce the drift already on these machines: two boxes carrying
     * different images under one name, because `apk` resolves at build time.
     */
    public function testTheTagIsPinnedToAVersionRatherThanFloating(): void
    {
        $tag = CodingImageService::pinnedTag();

        $this->assertStringStartsWith('kanvas/opencode:', $tag);
        $this->assertDoesNotMatchRegularExpression('/:(latest|stable|[0-9]+)$/', $tag);
        $this->assertMatchesRegularExpression('/:\d+\.\d+\.\d+/', $tag);
    }
}
