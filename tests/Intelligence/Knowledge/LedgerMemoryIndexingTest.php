<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event as EventFacade;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Knowledge\Enums\KnowledgeConfigurationEnum;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Enums\EventStatusEnum;
use Kanvas\NervousSystem\Ledger\Models\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;
use Tests\Traits\StubsTypesenseCredentials;

/** Serial: app settings (Redis). */
#[Group('serial')]
class LedgerMemoryIndexingTest extends TestCase
{
    use DatabaseTransactions;
    use StubsTypesenseCredentials;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $kanvasApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->enableAgentMemoryFor($this->kanvasApp);
    }

    protected function tearDown(): void
    {
        $this->restoreAgentMemoryFor($this->kanvasApp);

        parent::tearDown();
    }

    public function testASavedMemoryAsksToBeIndexedAndTelemetryDoesNot(): void
    {
        EventFacade::fake([KnowledgeIndexRequested::class]);

        $memory = $this->append('agent.knowledge.saved', ['title' => 'Acme prefers quarterly invoicing', 'content' => 'Agreed with their CFO.']);
        $this->append('lead.viewed', ['title' => 'nothing to remember']);

        EventFacade::assertDispatched(
            KnowledgeIndexRequested::class,
            fn (KnowledgeIndexRequested $request): bool => $request->entity->type === Event::class && $request->entity->id === $memory->getId()
        );
        EventFacade::assertDispatchedTimes(KnowledgeIndexRequested::class, 1);
    }

    public function testNothingIsIndexedWhenTheAppHasMemoryOff(): void
    {
        $this->kanvasApp->set(KnowledgeConfigurationEnum::AGENT_MEMORY_ENABLED->value, 0);
        EventFacade::fake([KnowledgeIndexRequested::class]);

        $this->append('agent.knowledge.saved', ['title' => 'A memory', 'content' => 'that stays in the ledger only.']);

        EventFacade::assertNotDispatched(KnowledgeIndexRequested::class);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function append(string $type, array $payload): Event
    {
        $user = auth()->user();

        return new AppendEventAction(new EventData(
            app: $this->kanvasApp,
            company: $user->getCurrentCompany(),
            sourceDomain: 'Test.Memory',
            eventType: $type,
            status: EventStatusEnum::INFO,
            actorType: 'Agent',
            actorId: 1,
            payload: $payload,
        ))->execute();
    }
}
