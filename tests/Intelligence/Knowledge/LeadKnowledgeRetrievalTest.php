<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\RAG\KnowledgeRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

class LeadKnowledgeRetrievalTest extends TestCase
{
    public function testRetrievalIsANoOpWithoutACurrentLead(): void
    {
        $documents = new KnowledgeRetrieval(null)->retrieve(
            new UserMessage('What did this lead say about pricing?')
        );

        $this->assertSame([], $documents);
    }
}
