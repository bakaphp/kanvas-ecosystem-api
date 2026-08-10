<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasKnowledgeRag;
use NeuronAI\RAG\RAG;
use Tests\TestCase;

class LeadRagAgentOptInTest extends TestCase
{
    public function testRagAgentsHaveKnowledgeRetrieval(): void
    {
        // RAG-enabled agents extend BaseRagAgent (→ NeuronAI RAG) and inherit HasKnowledgeRag.
        foreach ([SalesAgent::class, ReceptionistAgent::class, FollowUpAgent::class, CFOAgent::class] as $agent) {
            $this->assertTrue(is_subclass_of($agent, BaseRagAgent::class), "{$agent} should be a RAG agent");
            $this->assertTrue(is_subclass_of($agent, RAG::class));
            $this->assertContains(HasKnowledgeRag::class, class_uses_recursive($agent));
        }

        // A plain generic agent stays non-RAG.
        $this->assertTrue(is_subclass_of(KanvasGenericNeuronAgent::class, BaseKanvasAgent::class));
        $this->assertFalse(is_subclass_of(KanvasGenericNeuronAgent::class, RAG::class));
        $this->assertNotContains(HasKnowledgeRag::class, class_uses_recursive(KanvasGenericNeuronAgent::class));
    }
}
