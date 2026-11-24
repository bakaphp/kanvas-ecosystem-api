<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\Twilio\Services\CompanyPhoneNumbersService;
use Kanvas\Intelligence\Agents\Actions\Phone\ConfigureAgentInboundWebhookAction;
use Kanvas\Intelligence\Agents\Actions\Phone\ReleaseInboundWebhookForNumberAction;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Tests\Stubs\Intelligence\FakeTwilioNumbersClient;
use Tests\TestCase;
use Twilio\Exceptions\RestException;
use Twilio\Rest\Client;

class AgentInboundWebhookTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'intelligence', 'workflow'];

    private const string RUNTIME_URL = 'https://voice.test';
    private const string FOREIGN_URL = 'https://other-system.test/hook';

    private Apps $testApp;
    private Companies $company;
    private string $number;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testApp = app(Apps::class);
        $this->company = Companies::getById(auth()->user()->getCurrentCompany()->getId());
        $this->number = '+1555' . fake()->unique()->numerify('#######');
        $this->company->del(TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value);
        $this->company->del(TwilioConfigurationEnum::TWILIO_MESSAGING_SERVICE_SID->value);
        config(['kanvas.voice_runtime.url' => self::RUNTIME_URL]);
    }

    public function testAnEmptyVoiceWebhookIsSet(): void
    {
        $twilio = new FakeTwilioNumbersClient();

        $result = $this->configure($this->agent(voice: $this->number), $twilio);

        $this->assertSame('wired', $result['status']);
        $this->assertSame([
            [
                'voiceUrl' => VoiceRuntimeConfig::inboundWebhookUrl(),
                'voiceMethod' => 'POST',
            ],
        ], $twilio->updates);
    }

    public function testAForeignVoiceWebhookIsAlsoLeftInPlace(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: self::FOREIGN_URL);

        $result = $this->configure($this->agent(voice: $this->number), $twilio);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testAVoiceNumberOnAnotherRuntimeMovesHere(): void
    {
        $otherRuntime = 'https://sally-voice.test/twilio/incoming';
        $twilio = new FakeTwilioNumbersClient(voiceUrl: $otherRuntime);

        $result = $this->configure($this->agent(voice: $this->number), $twilio);

        $this->assertSame('wired', $result['status']);
        $this->assertSame(VoiceRuntimeConfig::inboundWebhookUrl(), $twilio->updates[0]['voiceUrl']);
        $this->assertStringContainsString($otherRuntime, $result['warnings'][0]);
    }

    public function testOnlyRuntimeInboundRoutesCountAsRuntimes(): void
    {
        $this->assertTrue(VoiceRuntimeConfig::isRuntimeInboundUrl('https://dev-kanvas-voice-agents-1.us-east1.run.app/twilio/incoming'));
        $this->assertTrue(VoiceRuntimeConfig::isRuntimeInboundUrl('https://voice.test/twilio/incoming/'));
        $this->assertFalse(VoiceRuntimeConfig::isRuntimeInboundUrl(self::FOREIGN_URL));
        $this->assertFalse(VoiceRuntimeConfig::isRuntimeInboundUrl('https://other.test/twilio/incoming-sms'));
        $this->assertFalse(VoiceRuntimeConfig::isRuntimeInboundUrl(''));
    }

    public function testTheRewireCommandListsVoiceNumbersOnADryRun(): void
    {
        $this->agent(voice: $this->number);

        $this->artisan('kanvas:voice-agents-rewire-numbers', ['--app' => $this->testApp->getId(), '--dry-run' => true])
            ->expectsOutputToContain(VoiceRuntimeConfig::inboundWebhookUrl())
            ->expectsOutputToContain($this->number)
            ->assertExitCode(0);
    }

    public function testOverwriteReplacesAForeignWebhook(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: self::FOREIGN_URL);

        $result = $this->configure(
            $this->agent(voice: $this->number),
            $twilio,
            overwrite: true
        );

        $this->assertSame('wired', $result['status']);
        $this->assertSame($result['url'], $twilio->updates[0]['voiceUrl']);
        $this->assertNotSame(self::FOREIGN_URL, $twilio->updates[0]['voiceUrl']);
    }

    public function testTwilioRefusingTheWebhookShowsItsReason(): void
    {
        $twilio = new FakeTwilioNumbersClient();
        $twilio->updateFailure = new RestException('VoiceUrl is not valid', 21402, 400);

        $result = $this->configure($this->agent(voice: $this->number), $twilio);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('VoiceUrl is not valid', $result['message']);
    }

    public function testTwilioRefusingTheReleaseShowsItsReason(): void
    {
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);
        $twilio->updateFailure = new RestException('Number is locked', 21400, 400);

        $result = $this->release(AgentPhoneChannelEnum::SMS, $twilio, force: true);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Number is locked', $result['message']);
    }

    public function testAllowedFromNumbersReadsEveryStoredShape(): void
    {
        $key = TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value;
        $expected = ['+15550000000', '+15550000001'];

        foreach ([$expected, json_encode($expected), '+15550000000, +15550000001', [15550000000, 15550000001]] as $stored) {
            $this->company->set($key, $stored);
            $this->assertSame($expected, TwilioConfigurationEnum::allowedFromNumbers($this->company));
        }

        $this->company->set($key, '15550000000');
        $this->assertSame(['+15550000000'], TwilioConfigurationEnum::allowedFromNumbers($this->company));

        $this->company->del($key);
        $this->assertSame([], TwilioConfigurationEnum::allowedFromNumbers($this->company));
    }

    public function testVoiceReleaseClearsOurWebhook(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: VoiceRuntimeConfig::inboundWebhookUrl());

        $result = $this->release(AgentPhoneChannelEnum::VOICE, $twilio);

        $this->assertSame('released', $result['status']);
        $this->assertSame('', $twilio->updates[0]['voiceUrl']);
    }

    public function testVoiceReleaseLeavesAForeignWebhook(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: self::FOREIGN_URL);

        $result = $this->release(AgentPhoneChannelEnum::VOICE, $twilio);

        $this->assertSame('kept', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testSmsReleaseKeepsTheWebhookUnlessForced(): void
    {
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);

        $this->assertSame('kept', $this->release(AgentPhoneChannelEnum::SMS, $twilio)['status']);
        $this->assertSame([], $twilio->updates);

        $this->assertSame('released', $this->release(AgentPhoneChannelEnum::SMS, $twilio, force: true)['status']);
        $this->assertSame('', $twilio->updates[0]['smsUrl']);
    }

    public function testForceDoesNotClearANumberAnotherAgentHolds(): void
    {
        $this->agent(sms: $this->number);
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);

        $result = $this->release(AgentPhoneChannelEnum::SMS, $twilio, force: true);

        $this->assertSame('kept', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testSettingANumberSavesItAndReportsTheWiring(): void
    {
        $agent = $this->agent();

        $response = $this->setPhoneNumber($agent, '+1 (555) ' . substr($this->number, 5, 3) . '-' . substr($this->number, 8));

        $this->assertSame($this->number, $response->json('data.setAgentPhoneNumber.phone_number'));
        $this->assertSame('error', $response->json('data.setAgentPhoneNumber.status'));
        $this->assertSame($this->number, $agent->fresh()->smsPhoneNumber());
        $this->assertSame('error', $agent->fresh()->sms_config['inbound_webhook']['status']);
    }

    public function testANullNumberRemovesItFromTheAgent(): void
    {
        $agent = $this->agent(sms: $this->number);

        $response = $this->setPhoneNumber($agent, null);

        $this->assertSame('idle', $response->json('data.setAgentPhoneNumber.status'));
        $this->assertNull($agent->fresh()->sms_config);
    }

    public function testATakenNumberIsRejectedAndNothingChanges(): void
    {
        $this->agent(sms: $this->number);
        $agent = $this->agent();

        $response = $this->setPhoneNumber($agent, $this->number);

        $this->assertStringContainsString('already assigned', (string) $response->json('errors.0.message'));
        $this->assertNull($agent->fresh()->sms_config);
    }

    public function testAnInvalidNumberDoesNotRemoveTheCurrentOne(): void
    {
        $agent = $this->agent(sms: $this->number);

        $response = $this->setPhoneNumber($agent, 'not a number');

        $this->assertStringContainsString('not a valid phone number', (string) $response->json('errors.0.message'));
        $this->assertSame($this->number, $agent->fresh()->smsPhoneNumber());
    }

    public function testTheNumberPickerTakesTheAgentId(): void
    {
        $agent = $this->agent();

        $response = $this->graphQL('
            query($id: ID) {
                agentAvailablePhoneNumbers(id: $id) {
                    phone_number
                }
            }
        ', ['id' => $agent->getId()]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $this->assertIsArray($response->json('data.agentAvailablePhoneNumbers'));
    }

    public function testListedNumbersNameTheirProvider(): void
    {
        $twilio = new FakeTwilioNumbersClient();
        $twilio->numbers = [
            (object) [
                'phoneNumber' => $this->number,
                'friendlyName' => 'Sales',
                'sid' => 'PNfake',
            ],
        ];

        $numbers = new class ($this->company, $twilio) extends CompanyPhoneNumbersService {
            public function __construct(Companies $company, private FakeTwilioNumbersClient $fake)
            {
                parent::__construct($company);
            }

            protected function twilio(): Client
            {
                return $this->fake;
            }
        }->list();

        $this->assertSame([
            [
                'phone_number' => $this->number,
                'friendly_name' => 'Sales',
                'provider' => 'twilio',
                'provider_id' => 'PNfake',
            ],
        ], $numbers);
    }

    public function testThePickerSourceNamesTheAccountWithoutItsFullSid(): void
    {
        $twilio = new FakeTwilioNumbersClient();

        $account = new class ($this->company, $twilio) extends CompanyPhoneNumbersService {
            public function __construct(Companies $company, private FakeTwilioNumbersClient $fake)
            {
                parent::__construct($company);
            }

            protected function twilio(): Client
            {
                return $this->fake;
            }
        }->account();

        $this->assertSame(['sid_suffix' => 'fake', 'friendly_name' => null, 'status' => null, 'type' => null], $account);
    }

    public function testThePickerSourceSaysWhichCompanyAndAccountOrWhyNot(): void
    {
        $response = $this->graphQL('
            query {
                agentPhoneNumberSource {
                    provider
                    company_name
                    account_sid_suffix
                    number_count
                    error
                }
            }
        ');

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $source = $response->json('data.agentPhoneNumberSource');
        $this->assertSame('twilio', $source['provider']);
        $this->assertSame($this->company->name, $source['company_name']);
        // Either the account answered (suffix set) or the reason it didn't is reported.
        $this->assertTrue(($source['error'] === null) === ($source['account_sid_suffix'] !== null));
    }

    private function setPhoneNumber(Agent $agent, ?string $number)
    {
        return $this->graphQL('
            mutation($id: ID!, $number: String) {
                setAgentPhoneNumber(id: $id, channel: SMS, phone_number: $number) {
                    phone_number
                    status
                }
            }
        ', [
            'id' => $agent->getId(),
            'number' => $number,
        ]);
    }

    private function configure(
        Agent $agent,
        FakeTwilioNumbersClient $twilio,
        bool $overwrite = false
    ): array {
        return new class ($agent, $overwrite, $twilio) extends ConfigureAgentInboundWebhookAction {
            public function __construct(
                Agent $agent,
                bool $overwrite,
                private FakeTwilioNumbersClient $fake
            ) {
                parent::__construct($agent, $overwrite);
            }

            protected function twilio(Companies $company): Client
            {
                return $this->fake;
            }
        }->execute();
    }

    private function release(AgentPhoneChannelEnum $channel, FakeTwilioNumbersClient $twilio, bool $force = false): array
    {
        return new class (
            $this->number,
            $this->company,
            $channel,
            $force,
            $twilio
        ) extends ReleaseInboundWebhookForNumberAction {
            public function __construct(
                string $number,
                Companies $company,
                AgentPhoneChannelEnum $channel,
                bool $force,
                private FakeTwilioNumbersClient $fake
            ) {
                parent::__construct(
                    number: $number,
                    company: $company,
                    channel: $channel,
                    force: $force
                );
            }

            protected function twilio(): Client
            {
                return $this->fake;
            }
        }->execute();
    }

    private function agent(?string $voice = null, ?string $sms = null): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'voice_config' => $voice !== null ? ['phone_number' => $voice] : null,
                'sms_config' => $sms !== null ? ['phone_number' => $sms] : null,
            ]);
    }
}
