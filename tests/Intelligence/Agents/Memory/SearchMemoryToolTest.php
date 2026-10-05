<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Memory;

use Kanvas\Intelligence\Agents\Neuron\Tools\System\SearchMemoryTool;
use NeuronAI\RAG\VectorStore\Filter\Filter;
use NeuronAI\RAG\VectorStore\MemoryVectorStore;
use Tests\Stubs\Intelligence\ConstantEmbeddingsProvider;
use Tests\Stubs\Intelligence\SharedCompanyMemory;
use Tests\TestCase;

/**
 * The on-demand half of memory: same store, same tenant and audience scope as the automatic recall,
 * plus a period and a limit the agent chooses.
 */
class SearchMemoryToolTest extends TestCase
{
    private const int APP = 1;

    private const int COMPANY = 101;

    private const int OTHER_COMPANY = 202;

    private const int SEPTEMBER = 1_788_000_000;

    public function testFindsLabelledMemoriesUnderTheTenantAndAudienceScope(): void
    {
        $store = $this->storeWith(
            $this->memory("User: What did Acme agree on invoicing?\nAssistant: Quarterly, confirmed by their CFO.", self::COMPANY, self::SEPTEMBER, users: 9),
            $this->memory("User: What did Acme agree on invoicing?\nAssistant: Monthly, says Globex.", self::OTHER_COMPANY, self::SEPTEMBER, users: 9),
            $this->memory("User: What did Acme agree on invoicing?\nAssistant: Someone else's chat.", self::COMPANY, self::SEPTEMBER, users: 77),
        );

        $result = $this->tool($store, Filter::eq('users_id', 9))->__invoke(query: 'Acme invoicing');

        $this->assertSame(1, $result['count']);
        $this->assertStringContainsString('Quarterly, confirmed by their CFO', $result['memories'][0]['content']);
        $this->assertSame('conversation', $result['memories'][0]['kind']);
        $this->assertSame(date('Y-m-d', self::SEPTEMBER), $result['memories'][0]['when']);
    }

    public function testAPeriodNarrowsTheSearch(): void
    {
        $store = $this->storeWith(
            $this->memory("User: Launch date?\nAssistant: The old plan said October 1.", self::COMPANY, self::SEPTEMBER - 90 * 86_400),
            $this->memory("User: Launch date?\nAssistant: Now November 12, confirmed.", self::COMPANY, self::SEPTEMBER),
        );

        $recent = $this->tool($store)->__invoke(query: 'launch date', since: date('Y-m-d', self::SEPTEMBER - 86_400));
        $older = $this->tool($store)->__invoke(query: 'launch date', until: date('Y-m-d', self::SEPTEMBER - 86_400));

        $this->assertSame(1, $recent['count']);
        $this->assertStringContainsString('November 12', $recent['memories'][0]['content']);
        $this->assertSame(1, $older['count']);
        $this->assertStringContainsString('October 1', $older['memories'][0]['content']);
    }

    public function testTheLimitIsHonouredAndAMissSaysSo(): void
    {
        $store = $this->storeWith(
            $this->memory("User: Office?\nAssistant: Santo Domingo.", self::COMPANY, self::SEPTEMBER),
            $this->memory("User: Office?\nAssistant: Piantini, floor 3.", self::COMPANY, self::SEPTEMBER),
        );

        $this->assertSame(1, $this->tool($store)->__invoke(query: 'office', limit: 1)['count']);

        $miss = $this->tool($store)->__invoke(query: 'office', since: '2030-01-01');

        $this->assertSame(0, $miss['count']);
        $this->assertStringContainsString('widen the period', $miss['message']);
    }

    public function testABadDateIsAnErrorTheModelCanActOn(): void
    {
        $result = $this->tool($this->storeWith())->__invoke(query: 'anything', since: 'not a date at all, really');

        $this->assertSame(0, $result['count']);
        $this->assertStringContainsString('must be dates', $result['message']);
    }

    private function tool(MemoryVectorStore $store, mixed $recallScope = null): SearchMemoryTool
    {
        return new SearchMemoryTool(
            store: $store,
            embeddings: new ConstantEmbeddingsProvider(),
            appId: self::APP,
            companyId: self::COMPANY,
            recallScope: $recallScope,
        );
    }

    private function storeWith(mixed ...$documents): MemoryVectorStore
    {
        $store = SharedCompanyMemory::newStore();

        if ($documents !== []) {
            $store->addDocuments(new ConstantEmbeddingsProvider()->embedDocuments($documents));
        }

        return $store;
    }

    private function memory(string $content, int $companyId, int $createdAt, int $users = 0): mixed
    {
        return SharedCompanyMemory::document($content, 'conversation', self::APP, $companyId, $createdAt, ['users_id' => $users]);
    }
}
