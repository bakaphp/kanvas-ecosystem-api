<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Neuron;

use Kanvas\Intelligence\Agents\Contracts\ConversesWithCustomer;
use Kanvas\Intelligence\Agents\Neuron\BaseRagAgent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\RAG\PreProcessor\QueryTransformationPreProcessor;
use Override;
use ReflectionMethod;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

/**
 * The query rewrite is a full LLM call before every retrieval. A teammate's question is explicit and
 * the rewrite cannot see the conversation anyway, so an internal agent skips it; a customer-facing
 * agent keeps it for the prospect's terse one-liners.
 */
class KnowledgeRagPreProcessorsTest extends TestCase
{
    public function testAnInternalAgentRunsNoQueryRewrite(): void
    {
        $this->assertSame([], $this->preProcessorsOf(new SystemUserAgent()));
    }

    public function testACustomerFacingAgentKeepsTheQueryRewrite(): void
    {
        $agent = new class () extends BaseRagAgent implements ConversesWithCustomer {
            #[Override]
            protected function provider(): AIProviderInterface
            {
                return new FakeNeuronProvider();
            }
        };

        $processors = $this->preProcessorsOf($agent);

        $this->assertCount(1, $processors);
        $this->assertInstanceOf(QueryTransformationPreProcessor::class, $processors[0]);
    }

    private function preProcessorsOf(BaseRagAgent $agent): array
    {
        return new ReflectionMethod($agent, 'preProcessors')->invoke($agent);
    }
}
