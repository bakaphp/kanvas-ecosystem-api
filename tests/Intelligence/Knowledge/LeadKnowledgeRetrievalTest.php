<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

class LeadKnowledgeRetrievalTest extends TestCase
{
    public function testRetrievalIsANoOpWithoutTenantContext(): void
    {
        // No app/company in scope: retrieval short-circuits to [] before touching
        // the embedder or the store (the retrieve-docs path is covered live by
        // KnowledgeScopeIsolationTest).
        $documents = new KnowledgeRetrieval(null, null, null)->retrieve(
            new UserMessage('What does our refund policy say?')
        );

        $this->assertSame([], $documents);
    }
}
