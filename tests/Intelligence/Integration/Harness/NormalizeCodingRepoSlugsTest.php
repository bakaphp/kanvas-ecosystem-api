<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessStatusEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MemoryCategoryEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\CodingRepositoryMemory;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The backfill that folds a repository's two spellings back into one memory bucket.
 *
 * The merge is the part worth covering: `(apps_id, companies_id, repo_slug, content_hash)` is unique,
 * so the same lesson filed under both spellings collides, and the naive fix — move and let the insert
 * fail, or move and overwrite — loses a row and its report count.
 *
 * Serial because the command is a table-wide sweep: its `UPDATE ... WHERE repo_slug = ?` holds row
 * locks on every match for the life of this test's transaction, which is how a sibling process
 * inserting a session deadlocks against it.
 */
#[Group('serial')]
class NormalizeCodingRepoSlugsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    private const string URL = 'https://github.com/normtest/widgets';
    private const string CANONICAL = 'normtest/widgets';

    public function testASessionsUrlRepositoryBecomesOwnerName(): void
    {
        $session = $this->makeSession(self::URL);

        $this->artisan('kanvas:coding:normalize-repo-slugs')->assertSuccessful();

        $this->assertSame(self::CANONICAL, $session->refresh()->repo_slug);
    }

    public function testABareProjectNameIsLeftExactlyAsItIs(): void
    {
        $session = $this->makeSession('some-internal-project');

        $this->artisan('kanvas:coding:normalize-repo-slugs')->assertSuccessful();

        $this->assertSame('some-internal-project', $session->refresh()->repo_slug);
    }

    public function testMemoriesUnderTheUrlFormMoveToTheCanonicalBucket(): void
    {
        $memory = $this->makeMemory(self::URL, 'The intelligence connection is not the default one');

        $this->artisan('kanvas:coding:normalize-repo-slugs')->assertSuccessful();

        $this->assertSame(self::CANONICAL, $memory->refresh()->repo_slug);
    }

    /**
     * The same lesson learned under both spellings. One row survives carrying both report counts —
     * losing the count would quietly demote a repeatedly-confirmed lesson to a one-off.
     */
    public function testTheSameLessonFiledUnderBothSpellingsIsMergedAndItsCountsSummed(): void
    {
        $content = 'Migrations here run on the intelligence connection';
        $canonical = $this->makeMemory(self::CANONICAL, $content, timesReported: 2);
        $duplicate = $this->makeMemory(self::URL, $content, timesReported: 3);

        $this->artisan('kanvas:coding:normalize-repo-slugs')->assertSuccessful();

        $this->assertSame(5, $canonical->refresh()->times_reported);
        $this->assertNull(CodingRepositoryMemory::query()->find($duplicate->getId()));
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $session = $this->makeSession(self::URL);

        $this->artisan('kanvas:coding:normalize-repo-slugs', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame(self::URL, $session->refresh()->repo_slug);
    }

    private function makeSession(string $repoSlug): AgentTaskSession
    {
        $session = new AgentTaskSession();
        $session->apps_id = app(Apps::class)->getId();
        $session->companies_id = 0;
        $session->task_id = random_int(800000, 899999);
        $session->harness = HarnessEnum::OPENCODE->value;
        $session->status = HarnessStatusEnum::COMPLETED->value;
        $session->repo_slug = $repoSlug;
        $session->saveOrFail();

        return $session;
    }

    private function makeMemory(
        string $repoSlug,
        string $content,
        int $timesReported = 1
    ): CodingRepositoryMemory {
        $memory = new CodingRepositoryMemory();
        $memory->apps_id = app(Apps::class)->getId();
        $memory->companies_id = 0;
        $memory->repo_slug = $repoSlug;
        $memory->category = MemoryCategoryEnum::GOTCHA->value;
        $memory->content = $content;
        $memory->content_hash = CodingRepositoryMemory::hashFor($content);
        $memory->times_reported = $timesReported;
        $memory->saveOrFail();

        return $memory;
    }
}
