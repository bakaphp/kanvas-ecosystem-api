<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Voice\PlaceVoiceTestCallAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Tests\TestCase;

class VoiceAgentTestCallTest extends TestCase
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
        config([
            'kanvas.voice_runtime.url' => 'https://voice.test',
            'kanvas.voice_runtime.api_token' => 'runtime-token',
        ]);
    }

    public function testAPlacedTestCallIsRecordedOnTheAgent(): void
    {
        Http::fake(['*/outbound' => Http::response(['call_id' => 'call_123', 'status' => 'queued'])]);
        $agent = $this->agent();

        $this->place($agent);

        $voice = $agent->fresh()->voice_config;
        $this->assertSame('call_123', $voice['last_test_call']['call_id']);
        $this->assertNotEmpty($voice['last_test_call']['at']);
        $this->assertSame('+15312334854', $voice['phone_number']);
    }

    public function testARejectedTestCallIsNotRecorded(): void
    {
        Http::fake(['*/outbound' => Http::response(['detail' => 'no telephony'], 422)]);
        $agent = $this->agent();

        try {
            $this->place($agent);
            $this->fail('Expected the rejected call to throw');
        } catch (ValidationException) {
        }

        $this->assertArrayNotHasKey('last_test_call', $agent->fresh()->voice_config);
    }

    private function place(Agent $agent): void
    {
        new PlaceVoiceTestCallAction(
            $agent,
            $this->testApp,
            $this->company,
            '+18095550100'
        )->execute();
    }

    private function agent(): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['voice_config' => ['phone_number' => '+15312334854']]);
    }
}
