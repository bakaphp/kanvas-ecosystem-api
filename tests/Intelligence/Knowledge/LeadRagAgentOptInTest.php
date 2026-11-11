<?php

declare(strict_types=1);

namespace Tests\Intelligence\Knowledge;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Accounting\CFOAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseKanvasAgent;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\Concerns\HasKnowledgeRag;
use Kanvas\Intelligence\Agents\Neuron\CRM\FollowUpAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\ReceptionistAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesAgent;
use Kanvas\Intelligence\Agents\Neuron\CRM\SalesManagerAgent;
use Kanvas\Intelligence\Agents\Neuron\KanvasGenericNeuronAgent;
use Kanvas\Intelligence\Agents\Neuron\RAG\Retrieval\KnowledgeRetrieval;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\RAG\Document;
use NeuronAI\RAG\PostProcessor\AdaptiveThresholdPostProcessor;
use NeuronAI\RAG\RAG;
use ReflectionMethod;
use ReflectionProperty;
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

    public function testRagAgentsUseAdaptiveThresholdPostProcessing(): void
    {
        $method = new ReflectionMethod(SalesAgent::class, 'postProcessors');
        $processors = $method->invoke(new SalesAgent());

        $this->assertCount(1, $processors);
        $this->assertInstanceOf(AdaptiveThresholdPostProcessor::class, $processors[0]);
    }

    /**
     * An internal agent on a lead reads that lead in its history and through the lead tools, so its
     * knowledge search never gets the lead's rows; a customer-facing agent does, until the per-message
     * index is retired.
     */
    public function testOnlyCustomerFacingAgentsSearchTheLeadInScope(): void
    {
        $lead = new Lead()->forceFill(['id' => 55, 'apps_id' => 11, 'companies_id' => 22]);
        $entity = new ReflectionProperty(KnowledgeRetrieval::class, 'entity');
        $method = new ReflectionMethod(BaseRagAgent::class, 'retrieval');

        $internal = new SalesManagerAgent();
        $internal->setCurrentLead($lead);
        $customerFacing = new SalesAgent();
        $customerFacing->setCurrentLead($lead);

        $internalRetrieval = $method->invoke($internal);
        $customerRetrieval = $method->invoke($customerFacing);

        $this->assertInstanceOf(KnowledgeRetrieval::class, $internalRetrieval);
        $this->assertInstanceOf(KnowledgeRetrieval::class, $customerRetrieval);
        $this->assertNull($entity->getValue($internalRetrieval));
        $this->assertSame($lead, $entity->getValue($customerRetrieval));
    }

    public function testAdaptiveThresholdDropsTheWeakestObservedResult(): void
    {
        $documents = array_map(function (float $score): Document {
            $document = new Document("Document with score {$score}");
            $document->setScore($score);

            return $document;
        }, [0.61545687913895, 0.54314804077148, 0.52687251567841]);

        $filtered = new AdaptiveThresholdPostProcessor(multiplier: 0.6)->process(
            new UserMessage('Test query'),
            $documents,
        );

        $this->assertCount(2, $filtered);
        $this->assertSame(0.61545687913895, $filtered[0]->getScore());
        $this->assertSame(0.54314804077148, $filtered[1]->getScore());
    }
}
