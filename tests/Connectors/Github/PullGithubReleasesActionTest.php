<?php

declare(strict_types=1);

namespace Tests\Connectors\Github;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Github\Actions\PullGithubReleasesAction;
use Kanvas\Connectors\Github\Client;
use Kanvas\Connectors\Github\DataTransferObject\GithubRelease;
use Tests\TestCase;

final class PullGithubReleasesActionTest extends TestCase
{
    private function release(string $tag, string $publishedAt, array $overrides = []): array
    {
        return array_merge([
            'tag_name' => $tag,
            'name' => $tag,
            'body' => "## A Feature\n\nSomething customers can read.",
            'published_at' => $publishedAt,
            'draft' => false,
            'prerelease' => false,
            'html_url' => 'https://github.com/acme/repo/releases/tag/' . $tag,
        ], $overrides);
    }

    /** @var array<string, array<int, array<int, array<string, mixed>>>> */
    private array $pages = [];

    private function clientReturning(array $pagesByRepo): Client
    {
        $this->pages = $pagesByRepo;

        return new class ($pagesByRepo) extends Client {
            public function __construct(private readonly array $pages)
            {
            }

            public function releases(string $repository, int $page = 1): array
            {
                return $this->pages[$repository][$page - 1] ?? [];
            }
        };
    }

    private function action(Client $client): PullGithubReleasesAction
    {
        return new PullGithubReleasesAction(app(Apps::class), array_keys($this->pages), $client);
    }

    public function testReturnsOnlyReleasesAfterTheWatermarkOldestFirst(): void
    {
        $client = $this->clientReturning(['acme/api' => [[
            $this->release('v2.0.2', '2026-08-20T10:00:00Z'),
            $this->release('v2.0.1', '2026-08-10T10:00:00Z'),
            $this->release('v2.0.0', '2026-07-01T10:00:00Z'),
        ]]]);

        $releases = $this->action($client)->publishedSince(Carbon::parse('2026-08-05T00:00:00Z'));

        $this->assertSame(
            ['v2.0.1', 'v2.0.2'],
            array_map(fn (GithubRelease $r): string => $r->tag, $releases),
            'oldest first, and nothing at or before the watermark'
        );
    }

    /**
     * The reason ordering is on published_at and never the tag: "v1.99.10" sorts BEFORE "v1.99.9"
     * lexically, so a tag-ordered feed silently drops the tenth patch release of a minor.
     */
    public function testTagsAreNotComparedAsStrings(): void
    {
        $client = $this->clientReturning(['acme/api' => [[
            $this->release('v1.99.10', '2026-08-20T10:00:00Z'),
            $this->release('v1.99.9', '2026-08-15T10:00:00Z'),
        ]]]);

        $releases = $this->action($client)->publishedSince(Carbon::parse('2026-08-01T00:00:00Z'));

        $this->assertSame(
            ['v1.99.9', 'v1.99.10'],
            array_map(fn (GithubRelease $r): string => $r->tag, $releases)
        );
        $this->assertTrue(strcmp('v1.99.10', 'v1.99.9') < 0, 'string compare really does invert these');
    }

    public function testTwoRepositoriesMergeIntoOneTimelineInterleavedByDate(): void
    {
        $client = $this->clientReturning([
            'acme/api' => [[$this->release('api-2', '2026-08-20T10:00:00Z'), $this->release('api-1', '2026-08-05T10:00:00Z')]],
            'acme/web' => [[$this->release('web-1', '2026-08-12T10:00:00Z')]],
        ]);

        $releases = $this->action($client)->publishedSince(Carbon::parse('2026-08-01T00:00:00Z'));

        $this->assertSame(
            ['api-1', 'web-1', 'api-2'],
            array_map(fn (GithubRelease $r): string => $r->tag, $releases),
            'one merged timeline, not grouped by repo'
        );
    }

    public function testDraftsPrereleasesAndEmptyNotesAreExcluded(): void
    {
        $client = $this->clientReturning(['acme/api' => [[
            $this->release('v3.0.0', '2026-08-20T10:00:00Z', ['draft' => true]),
            $this->release('v2.9.9', '2026-08-19T10:00:00Z', ['prerelease' => true]),
            $this->release('v2.9.8', '2026-08-18T10:00:00Z', ['body' => '   ']),
            $this->release('v2.9.7', '2026-08-17T10:00:00Z'),
        ]]]);

        $releases = $this->action($client)->publishedSince(Carbon::parse('2026-08-01T00:00:00Z'));

        $this->assertSame(['v2.9.7'], array_map(fn (GithubRelease $r): string => $r->tag, $releases));
    }

    /**
     * A null watermark is a brand-new account, not "send them everything Kanvas ever shipped".
     */
    public function testNullWatermarkIsBoundedToThirtyDays(): void
    {
        $client = $this->clientReturning(['acme/api' => [[
            $this->release('recent', now()->subDays(3)->toIso8601String()),
            $this->release('ancient', now()->subDays(200)->toIso8601String()),
        ]]]);

        $releases = $this->action($client)->publishedSince(null);

        $this->assertSame(['recent'], array_map(fn (GithubRelease $r): string => $r->tag, $releases));
    }

    public function testClientSurfacesAFailedGithubResponse(): void
    {
        Http::fake(['api.github.com/*' => Http::response('nope', 503)]);

        $this->expectExceptionMessageMatches('/GitHub releases request failed/');

        new Client('token')->releases('acme/api');
    }
}
