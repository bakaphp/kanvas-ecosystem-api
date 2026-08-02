<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\Traits\HasLeadRag;
use Tests\TestCase;

class LeadRagAgentOptInTest extends TestCase
{
    public function testOnlySalesAgentOptsIntoLeadRag(): void
    {
        $this->assertContains(HasLeadRag::class, class_uses_recursive(SalesAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(ReceptionistAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(FollowUpAgent::class));
        $this->assertNotContains(HasLeadRag::class, class_uses_recursive(CFOAgent::class));
    }
}
