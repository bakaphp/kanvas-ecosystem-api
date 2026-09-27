<?php

declare(strict_types=1);

namespace Tests\Intelligence\Integration\Harness;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\OpenCode\DataTransferObject\CodingRepository;
use Kanvas\Connectors\OpenCode\Services\SessionContextBuilder;
use Kanvas\Intelligence\AgentRuntime\Harness\Actions\ExtractSessionMemoriesAction;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MemoryCategoryEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\CodingRepositoryMemory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class ExtractSessionMemoriesActionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'ecosystem', 'intelligence', 'social'];

    public function testHandoffIsSplitIntoCategorisedMemories(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $handoff = $this->handoffJson([
            'done' => ['Rewrote the queue worker bootstrap sequence'],
            'gotchas' => ['The intelligence connection is not the default one'],
            'decisions' => [
                ['what' => 'Kept the poller on the history endpoint', 'why' => 'the event stream has no replay'],
            ],
        ]);

        $session = $this->seedCompletedSession($app, $company, $agent, $handoff, 'widgets');
        $stored = new ExtractSessionMemoriesAction($session)->execute();

        $this->assertCount(2, $stored);

        $memories = $this->memoriesFor($app, $company, 'widgets');
        $byCategory = $memories->keyBy('category');

        $this->assertSame(
            'The intelligence connection is not the default one',
            $byCategory[MemoryCategoryEnum::GOTCHA->value]->content
        );

        $decision = $byCategory[MemoryCategoryEnum::DECISION->value]->content;
        $this->assertStringContainsString('Kept the poller on the history endpoint', $decision);
        $this->assertStringContainsString('the event stream has no replay', $decision);
        $this->assertStringContainsString(' — ', $decision);

        // `done` is a record of the run, not a claim about the repository.
        $this->assertEmpty($memories->filter(
            fn (CodingRepositoryMemory $memory): bool => str_contains($memory->content, 'Rewrote the queue worker')
        ));
    }

    public function testHandoffWrappedInProseOrAFencedBlockStillParses(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $json = $this->handoffJson(['gotchas' => ['Composer autoload must be dumped after a new namespace']]);
        $handoff = "Here you go:\n```json\n" . $json . "\n```\nLet me know if you need anything else.";

        $session = $this->seedCompletedSession($app, $company, $agent, $handoff, 'widgets');

        $this->assertCount(1, new ExtractSessionMemoriesAction($session)->execute());
        $this->assertSame(
            'Composer autoload must be dumped after a new namespace',
            $this->memoriesFor($app, $company, 'widgets')->first()?->content
        );
    }

    /**
     * The unfenced path scans for a balanced `{...}` by hand, over bytes. Counting the loop's end in
     * characters stopped it short of the closing brace on any handoff containing a multi-byte
     * character, so an agent writing Spanish silently stored nothing at all.
     */
    public function testAccentedProseAroundAnUnfencedHandoffStillYieldsMemories(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $json = $this->handoffJson(['gotchas' => ['La conexión de migraciones no es la predeterminada']]);
        $handoff = 'Ya está listo, aquí va el resumen de la sesión: ' . $json;

        $session = $this->seedCompletedSession($app, $company, $agent, $handoff, 'widgets');

        $this->assertCount(1, new ExtractSessionMemoriesAction($session)->execute());
        $this->assertSame(
            'La conexión de migraciones no es la predeterminada',
            $this->memoriesFor($app, $company, 'widgets')->first()?->content
        );
    }

    public function testReReportingTheSameLessonBumpsACounterInsteadOfAddingARow(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $handoff = $this->handoffJson(['gotchas' => ['Migrations here run on the intelligence connection']]);

        foreach ([1, 2] as $ignored) {
            $session = $this->seedCompletedSession($app, $company, $agent, $handoff, 'widgets');
            new ExtractSessionMemoriesAction($session)->execute();
        }

        $memories = $this->memoriesFor($app, $company, 'widgets');

        $this->assertCount(1, $memories);
        $this->assertSame(2, $memories->first()?->times_reported);
    }

    public function testASessionWithNoRepositoryProducesNoMemories(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $handoff = $this->handoffJson(['gotchas' => ['A lesson from an anonymous scratch directory']]);
        $session = $this->seedCompletedSession($app, $company, $agent, $handoff);

        $this->assertSame([], new ExtractSessionMemoriesAction($session)->execute());
        $this->assertSame(
            0,
            CodingRepositoryMemory::query()->where('source_session_id', $session->getId())->count()
        );
    }

    public function testEntriesTooShortToCarryALessonAreIgnored(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        // Below the action's MIN_LENGTH of 12 characters.
        $handoff = $this->handoffJson(['gotchas' => ['be careful', 'ok']]);
        $session = $this->seedCompletedSession($app, $company, $agent, $handoff, 'widgets');

        $this->assertSame([], new ExtractSessionMemoriesAction($session)->execute());
        $this->assertCount(0, $this->memoriesFor($app, $company, 'widgets'));
    }

    public function testContextDocumentPrefersDistilledMemoriesOverRawHandoffs(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $older = $this->handoffJson(['done' => ['RAW-HANDOFF-MARKER rebuilt the container image']]);
        $this->seedCompletedSession($app, $company, $agent, $older, 'widgets');

        $newer = $this->handoffJson(['gotchas' => ['The workspace has to be a git repository']]);
        $session = $this->seedCompletedSession($app, $company, $agent, $newer, 'widgets');
        new ExtractSessionMemoriesAction($session)->execute();

        $document = new SessionContextBuilder($agent, $this->repository('widgets'))->contextDocument();

        $this->assertNotNull($document);
        $this->assertStringContainsString('The workspace has to be a git repository', $document);
        $this->assertStringNotContainsString('RAW-HANDOFF-MARKER', $document);
        $this->assertStringNotContainsString('What previous sessions on this repository reported', $document);
    }

    public function testMemoriesFromAnotherRepositoryAreNeverIncluded(): void
    {
        [$app, $company, $user] = $this->context();
        $agent = $this->makeAgent($app, $company, $user);

        $gadgets = $this->handoffJson(['gotchas' => ['Gadgets pins its node version in .tool-versions']]);
        new ExtractSessionMemoriesAction(
            $this->seedCompletedSession($app, $company, $agent, $gadgets, 'gadgets')
        )->execute();

        $widgets = $this->handoffJson(['gotchas' => ['Widgets uses pnpm rather than npm']]);
        new ExtractSessionMemoriesAction(
            $this->seedCompletedSession($app, $company, $agent, $widgets, 'widgets')
        )->execute();

        $document = new SessionContextBuilder($agent, $this->repository('widgets'))->contextDocument();

        $this->assertNotNull($document);
        $this->assertStringContainsString('Widgets uses pnpm rather than npm', $document);
        $this->assertStringNotContainsString('Gadgets pins its node version', $document);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handoffJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return Collection<int, CodingRepositoryMemory>
     */
    private function memoriesFor(Apps $app, CompanyInterface $company, string $repoSlug): Collection
    {
        return CodingRepositoryMemory::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->forRepo($repoSlug)
            ->get();
    }

    private function repository(string $slug): CodingRepository
    {
        return new CodingRepository(
            slug: $slug,
            cloneUrl: 'https://git.example.test/acme/' . $slug . '.git',
        );
    }

    private function seedCompletedSession(
        Apps $app,
        CompanyInterface $company,
        Agent $agent,
        string $handoff,
        ?string $repoSlug = null
    ): AgentTaskSession {
        $session = new AgentTaskSession();
        $session->apps_id = $app->getId();
        $session->companies_id = $company->getId();
        $session->agent_id = $agent->getId();
        // No FK on the table, and the history query never joins the task.
        $session->task_id = 0;
        $session->repo_slug = $repoSlug;
        $session->harness = 'opencode';
        $session->status = 'completed';
        $session->handoff = $handoff;
        $session->completed_at = Carbon::now();
        $session->saveOrFail();

        return $session;
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function makeAgent(
        Apps $app,
        CompanyInterface $company,
        Users $user,
        array $attributes = []
    ): Agent {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => true,
                ...$attributes,
            ]);
    }

    /**
     * @return array{0: Apps, 1: CompanyInterface, 2: Users}
     */
    private function context(): array
    {
        /** @var Users $user */
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }
}
