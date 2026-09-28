<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\RememberKnowledgeTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Tests\TestCase;

class RememberKnowledgeToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'social', 'workflow'];

    private function tool(Agent $agent): RememberKnowledgeTool
    {
        $app = app(Apps::class);

        return new RememberKnowledgeTool($app, $agent->company, $agent);
    }

    private function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['user_id' => $user->getId()]);
    }

    public function testSavesADurableMemoryAsAPreservedLedgerEvent(): void
    {
        $agent = $this->makeAgent();

        $result = $this->tool($agent)->__invoke(
            title: 'Acme prefers quarterly invoicing',
            content: 'Acme asked to be billed once per quarter, not monthly — decided on the Q3 renewal call.',
            tags: 'billing, acme',
        );

        $this->assertSame('success', $result['status']);
        $this->assertSame(['billing', 'acme'], $result['tags']);

        $event = Event::query()
            ->where('event_type', 'agent.knowledge.saved')
            ->where('actor_type', 'Agent')
            ->where('actor_id', $agent->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'remember must emit an agent.knowledge.saved event');
        $this->assertSame('Acme prefers quarterly invoicing', $event->payload['title']);
        $this->assertSame(['billing', 'acme'], $event->payload['tags']);
    }

    public function testBlankTitleOrContentIsRejectedWithoutEmitting(): void
    {
        $agent = $this->makeAgent();

        $before = Event::query()->where('event_type', 'agent.knowledge.saved')->count();

        $result = $this->tool($agent)->__invoke(title: '   ', content: 'has content');

        $this->assertSame('error', $result['status']);
        $this->assertSame(
            $before,
            Event::query()->where('event_type', 'agent.knowledge.saved')->count(),
            'a rejected save must not emit an event',
        );
    }
}
