<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Enums\AgentLlmProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentLlmConfig;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;
use Tests\TestCase;

final class VoiceAgentLlmConfigTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private Apps $testApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
    }

    public function testWithoutACallConfigTheCallStaysOnGemini(): void
    {
        $llm = $this->callLlm($this->agent());

        $this->assertSame('google', $llm['provider']);
        $this->assertNull($llm['base_url']);
    }

    public function testTheCallConfigDrivesTheCallLlmWithoutItsKey(): void
    {
        $config = $this->llmConfig(companyId: $this->company->getId());

        $llm = $this->callLlm($this->agent(voiceConfig: ['llm_config_id' => $config->getId()]));

        $this->assertSame(
            [
                'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
                'model' => 'openai/gpt-oss-120b',
                'base_url' => 'https://openrouter.ai/api/v1',
            ],
            $llm
        );
    }

    public function testTheChatConfigDoesNotChangeCalls(): void
    {
        $agent = $this->agent();
        $agent->agent_llm_config_id = $this->llmConfig(companyId: $this->company->getId())->getId();
        $agent->saveOrFail();

        $this->assertSame('google', $this->callLlm($agent)['provider']);
    }

    public function testAnotherCompanysConfigIsIgnoredOnCalls(): void
    {
        $foreign = $this->llmConfig(companyId: Companies::factory()->create()->getId());

        $llm = $this->callLlm($this->agent(voiceConfig: ['llm_config_id' => $foreign->getId()]));

        $this->assertSame('google', $llm['provider']);
    }

    public function testSavingAnotherCompanysConfigForCallsIsRejected(): void
    {
        $agent = $this->agent();
        $foreign = $this->llmConfig(companyId: Companies::factory()->create()->getId());

        $response = $this->saveVoiceConfig($agent, ['llm_config_id' => $foreign->getId()]);

        $this->assertNotNull($response->json('errors'));
        $this->assertNull($agent->fresh()->voice_config['llm_config_id'] ?? null);
    }

    public function testSavingZeroClearsTheCallConfigLikeChat(): void
    {
        $config = $this->llmConfig(companyId: $this->company->getId());
        $agent = $this->agent(voiceConfig: ['llm_config_id' => $config->getId()]);

        $response = $this->saveVoiceConfig($agent, ['llm_config_id' => 0]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame('google', $this->callLlm($agent->fresh())['provider']);
    }

    private function saveVoiceConfig(Agent $agent, array $voiceConfig)
    {
        return $this->graphQL('
            mutation($input: AgentAiInput!) {
                updateAiAgent(id: ' . $agent->getId() . ', input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'agent_type_id' => AgentType::factory()->withAppId($this->testApp->getId())->create()->getId(),
                'name' => 'Agent ' . uniqid(),
                'description' => 'Test',
                'config' => [],
                'role' => 'test',
                'is_active' => true,
                'voice_config' => $voiceConfig,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function callLlm(Agent $agent): array
    {
        return new VoiceAgentSpecService($agent, $this->testApp)->compile()['models']['llm'];
    }

    private function agent(?array $voiceConfig = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['voice_config' => $voiceConfig]);
    }

    private function llmConfig(int $companyId): AgentLlmConfig
    {
        return AgentLlmConfig::factory()->create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $companyId,
            'provider' => AgentLlmProviderEnum::OPENAI_LIKE->value,
            'base_uri' => 'https://openrouter.ai/api/v1',
            'model' => 'openai/gpt-oss-120b',
            'is_active' => true,
        ]);
    }
}
