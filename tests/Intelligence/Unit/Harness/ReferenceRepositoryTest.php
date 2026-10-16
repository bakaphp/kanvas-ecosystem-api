<?php

declare(strict_types=1);

namespace Tests\Intelligence\Unit\Harness;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\Actions\PrepareSessionWorktreeAction;
use Kanvas\Connectors\OpenCode\Actions\PushSessionBranchAction;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Jobs\LaunchTaskSessionJob;
use Kanvas\Intelligence\Agents\Models\Agent;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The checkout itself needs a machine and is covered end to end. Guarded here is the part with no
 * second chance: a reference is somebody else's codebase inside this repository's working tree, and
 * only two lists keep it out of a pull request.
 */
class ReferenceRepositoryTest extends TestCase
{
    public function testAnEntryIsNotAReferenceUnlessItSaysSo(): void
    {
        $repository = CodingRepository::fromAllowListEntry([
            'slug' => 'kanvas-app',
            'url' => 'https://github.com/mctekk/kanvas-app.git',
        ]);

        $this->assertNotNull($repository);
        $this->assertFalse($repository->reference);
    }

    public function testAnEntryFlaggedAsAReferenceIsParsedAsOne(): void
    {
        $repository = CodingRepository::fromAllowListEntry([
            'slug' => 'kanvas-app',
            'url' => 'https://github.com/mctekk/kanvas-app.git',
            'reference' => true,
        ]);

        $this->assertNotNull($repository);
        $this->assertTrue($repository->reference);
    }

    /**
     * `KANVAS_ARTIFACTS` is stripped before every commit and also filters `git status --porcelain`, so
     * dropping the reference directory commits another repository AND breaks empty-run detection.
     */
    public function testTheReferenceDirectoryCannotBeCommitted(): void
    {
        $artifacts = new ReflectionClass(PushSessionBranchAction::class)
            ->getConstant('KANVAS_ARTIFACTS');

        $this->assertIsArray($artifacts);
        $this->assertContains(
            PrepareSessionWorktreeAction::REFERENCE_DIR,
            $artifacts,
            'A reference checkout is another repository. Removing it from KANVAS_ARTIFACTS means '
                . 'committing that repository into this one.',
        );
    }

    /** A bare name like `reference` would collide with a real directory and silently stop it committing. */
    public function testTheReferenceDirectoryIsHidden(): void
    {
        $this->assertStringStartsWith('.', PrepareSessionWorktreeAction::REFERENCE_DIR);
    }

    public function testOnlyFlaggedEntriesAreStandingReferences(): void
    {
        $slugs = array_map(
            static fn (CodingRepository $repository): string => $repository->slug,
            new RepoAllowListService($this->agentWithRepos())->references()
        );

        $this->assertSame(['kanvas-app'], $slugs);
    }

    public function testTheWorkingRepositoryIsNeverAlsoCheckedOutAsAReference(): void
    {
        // An agent whose standing references include the repo it happens to be working on today is a
        // normal thing to configure. Checking it out twice would give the model the same tree at two
        // paths with only one of them writable, which is worse than either having it or not.
        $working = CodingRepository::fromAllowListEntry(['slug' => 'kanvas-app', 'url' => 'https://x/y.git']);

        $this->assertSame([], $this->resolveReferences($this->agentWithRepos(), $working, []));
    }

    public function testAReferenceNamedTwiceIsCheckedOutOnce(): void
    {
        // 'kanvas-app' is already standing, so naming it on the dispatch as well must not duplicate it.
        $slugs = $this->resolveReferences($this->agentWithRepos(), null, ['kanvas-app', 'agent-sandbox']);

        $this->assertSame(['kanvas-app', 'agent-sandbox'], $slugs);
    }

    /** Named is a request, not a hint: quietly proceeding produces findings about a directory never there. */
    public function testANamedReferenceThatCannotBeOpenedFailsTheJob(): void
    {
        $this->expectException(ValidationException::class);

        // No git token on this agent, so `discover()` cannot fall back to asking GitHub either.
        $this->resolveReferences($this->agentWithRepos(), null, ['no-such-repo']);
    }

    /**
     * @param list<string> $named
     * @return list<string>
     */
    private function resolveReferences(Agent $agent, ?CodingRepository $working, array $named): array
    {
        $job = new LaunchTaskSessionJob(
            app: Mockery::mock(Apps::class)->makePartial(),
            sessionId: 1,
            brief: 'anything',
            referenceSlugs: $named,
        );

        $resolve = new ReflectionMethod($job, 'resolveReferences');

        /** @var list<CodingRepository> $references */
        $references = $resolve->invoke($job, $agent, $working);

        return array_map(static fn (CodingRepository $r): string => $r->slug, $references);
    }

    /** Two repositories, one of them a standing reference — the shape an agent is configured with. */
    private function agentWithRepos(): Agent
    {
        $agent = Mockery::mock(Agent::class)->makePartial();
        $agent->shouldReceive('get')->andReturn(json_encode([
            ['slug' => 'agent-sandbox', 'url' => 'https://github.com/mctekk/agent-sandbox.git'],
            ['slug' => 'kanvas-app', 'url' => 'https://github.com/mctekk/kanvas-app.git', 'reference' => true],
        ]));

        return $agent;
    }
}
