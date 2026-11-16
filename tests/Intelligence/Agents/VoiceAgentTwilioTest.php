<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use App\GraphQL\Intelligence\Queries\Agent\VoiceAgentTwilioQuery;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Actions\Voice\GetVoiceAgentTwilioCredentialsAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Nuwave\Lighthouse\Exceptions\AuthorizationException;
use Tests\TestCase;

class VoiceAgentTwilioTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence'];

    private const string TOKEN = 'runtime-token';

    private Apps $testApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::factory()->create();
        $this->company->set(ConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'ACcompany');
        $this->company->set(ConfigurationEnum::TWILIO_AUTH_TOKEN->value, 'company-token');
        config(['kanvas.voice_runtime.api_token' => self::TOKEN]);
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $this->company->del(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);

        parent::tearDown();
    }

    public function testAVoiceAgentGetsItsCompanysCredentials(): void
    {
        $credentials = new GetVoiceAgentTwilioCredentialsAction($this->agent())->execute();

        $this->assertSame(['account_sid' => 'ACcompany', 'auth_token' => 'company-token'], $credentials);
    }

    public function testAnAgentWithoutVoiceGetsNothing(): void
    {
        $this->expectException(ValidationException::class);

        new GetVoiceAgentTwilioCredentialsAction($this->agent(voice: false))->execute();
    }

    public function testACompanyWithoutTwilioGetsNothing(): void
    {
        $this->company->del(ConfigurationEnum::TWILIO_AUTH_TOKEN->value);

        $this->expectException(ValidationException::class);

        new GetVoiceAgentTwilioCredentialsAction($this->agent())->execute();
    }

    public function testAnAgentWithoutACompanyGetsNothing(): void
    {
        $agent = $this->agent();
        $agent->companies_id = 0;

        $this->expectException(ValidationException::class);

        new GetVoiceAgentTwilioCredentialsAction($agent)->execute();
    }

    public function testOnlyTheRuntimesTokenIsAccepted(): void
    {
        $this->assertTrue(VoiceRuntimeConfig::acceptsToken(self::TOKEN));
        $this->assertFalse(VoiceRuntimeConfig::acceptsToken('another-token'));
        $this->assertFalse(VoiceRuntimeConfig::acceptsToken(null));

        config(['kanvas.voice_runtime.api_token' => '']);
        $this->assertFalse(VoiceRuntimeConfig::acceptsToken(''));
    }

    public function testTheQueryRefusesAnAppKeyWithoutTheRuntimeToken(): void
    {
        $agent = $this->agent();
        request()->headers->set(VoiceAgentTwilioQuery::TOKEN_HEADER, 'wrong');

        $this->expectException(AuthorizationException::class);

        new VoiceAgentTwilioQuery()(null, ['agent_uuid' => $agent->uuid]);
    }

    public function testTheQueryAnswersTheRuntime(): void
    {
        $agent = $this->agent();
        request()->headers->set(VoiceAgentTwilioQuery::TOKEN_HEADER, self::TOKEN);

        $credentials = new VoiceAgentTwilioQuery()(null, ['agent_uuid' => $agent->uuid]);

        $this->assertSame('ACcompany', $credentials['account_sid']);
    }

    private function agent(bool $voice = true): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create(['voice_config' => $voice ? ['phone_number' => '+15312334854'] : null]);
    }
}
