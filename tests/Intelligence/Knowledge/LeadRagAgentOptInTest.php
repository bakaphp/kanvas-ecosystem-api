<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasLeadRag;
use NeuronAI\RAG\RAG;
use Tests\TestCase;

class LeadRagAgentOptInTest extends TestCase
{
    public function testOnlySalesAgentOptsIntoLeadRag(): void
    {
        $this->assertTrue(is_subclass_of(SalesAgent::class, BaseRagAgent::class));
        $this->assertTrue(is_subclass_of(SalesAgent::class, RAG::class));
        $this->assertFalse(is_subclass_of(SalesAgent::class, BaseKanvasAgent::class));
        $this->assertFalse(is_subclass_of(BaseKanvasAgent::class, RAG::class));
        $this->assertTrue(is_subclass_of(ReceptionistAgent::class, BaseKanvasAgent::class));
        $this->assertTrue(is_subclass_of(FollowUpAgent::class, BaseKanvasAgent::class));
        $this->assertTrue(is_subclass_of(CFOAgent::class, BaseKanvasAgent::class));

        $this->assertContains(HasLeadRag::class, class_uses_recursive(SalesAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(ReceptionistAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(FollowUpAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(CFOAgent::class));
    }
}
