<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Knowledge\Retrieval\LeadKnowledgeRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use Tests\TestCase;

class LeadKnowledgeRetrievalTest extends TestCase
{
    public function testRetrievalIsANoOpWithoutACurrentLead(): void
    {
        $documents = new LeadKnowledgeRetrieval(null)->retrieve(
            new UserMessage('What did this lead say about pricing?')
        );

        $this->assertSame([], $documents);
    }
}
