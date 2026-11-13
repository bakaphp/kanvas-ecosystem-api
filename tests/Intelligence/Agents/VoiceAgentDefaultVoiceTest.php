<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceAgentSpecService;
use Tests\TestCase;

final class VoiceAgentDefaultVoiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private const string DEFAULT_VOICE = 'uYXf8XasLslADfZ2MB4u';

    private Apps $testApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
        config(['kanvas.voice_runtime.default_voice_id' => self::DEFAULT_VOICE]);
    }

    public function testAnAgentWithoutAVoiceSpeaksWithTheDefault(): void
    {
        $this->assertSame(self::DEFAULT_VOICE, $this->voiceId(['tts' => ['voice_id' => '']]));
        $this->assertSame(self::DEFAULT_VOICE, $this->voiceId([]));
    }

    public function testTheAgentsOwnVoiceWins(): void
    {
        $this->assertSame('agent-voice', $this->voiceId(['tts' => ['voice_id' => 'agent-voice']]));
    }

    public function testWithoutADefaultTheRuntimeChooses(): void
    {
        config(['kanvas.voice_runtime.default_voice_id' => '']);

        $this->assertNull($this->voiceId([]));
    }

    public function testAnAgentWithoutALanguageSpeaksEnglish(): void
    {
        $agent = Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['voice_config' => ['phone_number' => null]]);

        $spec = new VoiceAgentSpecService($agent, $this->testApp)->compile();

        $this->assertSame('en', $spec['language']);
        $this->assertSame('en', $spec['models']['stt']['language']);
    }

    public function testTheAdminCanReadTheDefault(): void
    {
        $response = $this->graphQL('query { voiceAgentDefaults { voice_id } }');

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertSame(self::DEFAULT_VOICE, $response->json('data.voiceAgentDefaults.voice_id'));
    }

    /**
     * @param array<string, mixed> $voiceConfig
     */
    private function voiceId(array $voiceConfig): ?string
    {
        $agent = Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['voice_config' => $voiceConfig ?: ['phone_number' => null]]);

        return new VoiceAgentSpecService($agent, $this->testApp)->compile()['models']['tts']['voice_id'];
    }
}
