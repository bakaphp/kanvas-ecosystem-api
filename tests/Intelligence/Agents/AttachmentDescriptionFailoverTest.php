<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use Aws\Command;
use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Factories\AgentLlmConfigFactory;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasBedrock;
use Kanvas\Intelligence\Agents\Neuron\Providers\KanvasRouterProvider;
use Kanvas\Intelligence\Agents\Services\AttachmentDescriptionService;
use Mockery;
use NeuronAI\Router\RouterProvider;
use ReflectionProperty;
use Tests\Stubs\Intelligence\CapturingNeuronAgentStub;
use Tests\Stubs\Intelligence\FakeNeuronProvider;
use Tests\TestCase;

/**
 * KANVAS-ECOSYSTEM-70B: Llama on Bedrock answered caption requests with a 408 in under a second, and
 * each attachment was left without a caption though the same call succeeded a moment later.
 */
final class AttachmentDescriptionFailoverTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    public function testAnAttachmentIsCaptionedByTheFallbackWhenTheModelTimesOut(): void
    {
        $timedOut = new BedrockRuntimeException(
            'Model has timed out in processing the request.',
            new Command('Converse'),
            [
                'response' => new Response(408),
                'code' => 'ModelTimeoutException',
            ],
        );
        $client = Mockery::mock(BedrockRuntimeClient::class);
        $client->shouldReceive('converseAsync')->once()->andReturn(new RejectedPromise($timedOut));

        $chain = KanvasRouterProvider::make()
            ->addProvider('primary', new KanvasBedrock($client, 'us.meta.llama4-scout-17b-instruct-v1:0'))
            ->addProvider('fallback', new FakeNeuronProvider('a bowl of chicken and carrots'))
            ->setFallbackOrder('primary', 'fallback');

        $this->assertSame(
            'Image: a bowl of chicken and carrots',
            new AttachmentDescriptionService($chain)->describeBinary(base64_decode(self::PNG), 'image/png'),
        );
    }

    public function testTheCaptionJobAsksThroughTheAgentsFailoverChain(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $fallback = AgentLlmConfigFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['is_routing_enabled' => true]);
        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create(['provider' => 'neuron', 'handler' => CapturingNeuronAgentStub::class]);
        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['agent_type_id' => $agentType->getId(), 'config' => []]);

        $describer = AttachmentDescriptionService::forAgent($agent, auth()->user());

        $this->assertNotNull($describer);

        $provider = new ReflectionProperty($describer, 'provider')->getValue($describer);

        $this->assertInstanceOf(RouterProvider::class, $provider);
        $this->assertContains(
            'llm_config_' . $fallback->getId(),
            new ReflectionProperty(RouterProvider::class, 'fallbackOrder')->getValue($provider),
        );
    }
}
