<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\OpenCode\Services\GitHubRepositoryService;
use Tests\TestCase;

/**
 * What an agent is allowed to close, and what it must say first.
 *
 * Closing is the one pull-request lifecycle action an agent has, and it is only defensible on a pull
 * request it opened that nobody has looked at. Everything here is a refusal that keeps it that way.
 */
class ClosePullRequestGuardTest extends TestCase
{
    private const string REPO = 'https://github.com/mctekk/agent-sandbox.git';

    public function testAReviewedPullRequestIsNotClosed(): void
    {
        Http::fake([
            'api.github.com/repos/*/pulls/7' => Http::response(['state' => 'open', 'merged_at' => null]),
            'api.github.com/repos/*/pulls/7/reviews*' => Http::response([['id' => 1, 'state' => 'CHANGES_REQUESTED']]),
        ]);

        $result = new GitHubRepositoryService('tok', self::REPO)->close(7, 'Superseded by #9.');

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('reviewed by someone', $result['reason']);
    }

    public function testAMergedPullRequestIsNotClosed(): void
    {
        Http::fake([
            'api.github.com/repos/*/pulls/7' => Http::response(['state' => 'closed', 'merged_at' => '2026-09-27T00:00:00Z']),
        ]);

        $result = new GitHubRepositoryService('tok', self::REPO)->close(7, 'Superseded by #9.');

        $this->assertFalse($result['closed']);
        $this->assertStringContainsString('already merged', $result['reason']);
    }

    /**
     * The reason goes on the thread BEFORE the state changes, and a failure to post stops the close —
     * a pull request that shuts with no explanation leaves its reviewer nowhere to look.
     */
    public function testNothingIsClosedWhenTheReasonCannotBePosted(): void
    {
        Http::fake([
            'api.github.com/repos/*/pulls/7' => Http::response(['state' => 'open', 'merged_at' => null]),
            'api.github.com/repos/*/pulls/7/reviews*' => Http::response([]),
            'api.github.com/repos/*/issues/7/comments' => Http::response([], 403),
        ]);

        $result = new GitHubRepositoryService('tok', self::REPO)->close(7, 'Superseded by #9.');

        $this->assertFalse($result['closed']);
        Http::assertNotSent(static fn ($request): bool => $request->method() === 'PATCH');
    }

    public function testAnUnreviewedPullRequestIsCommentedThenClosed(): void
    {
        Http::fake([
            'api.github.com/repos/*/pulls/7' => Http::response(['state' => 'open', 'merged_at' => null]),
            'api.github.com/repos/*/pulls/7/reviews*' => Http::response([]),
            'api.github.com/repos/*/issues/7/comments' => Http::response(['id' => 1]),
        ]);

        $result = new GitHubRepositoryService('tok', self::REPO)->close(7, 'Superseded by #9.');

        $this->assertTrue($result['closed']);
        Http::assertSent(static fn ($request): bool => $request->method() === 'PATCH'
            && $request['state'] === 'closed');
    }
}
