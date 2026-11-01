<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Connectors\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Intelligence\Agents\Actions\Phone\ConfigureAgentInboundWebhookAction;
use Kanvas\Intelligence\Agents\Actions\Phone\ReleaseInboundWebhookForNumberAction;
use Kanvas\Intelligence\Agents\Enums\AgentPhoneChannelEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
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

        $result = $this->configure($this->agent(voice: $this->number), AgentPhoneChannelEnum::VOICE, $twilio);

        $this->assertSame('wired', $result['status']);
        $this->assertSame([
            [
                'voiceUrl' => VoiceRuntimeConfig::inboundWebhookUrl($this->testApp),
                'voiceMethod' => 'POST',
            ],
        ], $twilio->updates);
    }

    public function testAnEmptySmsWebhookPointsAtTheCompanyReceiver(): void
    {
        $receiver = $this->receiver();
        $twilio = new FakeTwilioNumbersClient();

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, $twilio);

        $this->assertSame('wired', $result['status']);
        $this->assertSame([
            [
                'smsUrl' => $receiver->getUrl(),
                'smsMethod' => 'POST',
            ],
        ], $twilio->updates);
    }

    public function testASmsNumberCreatesTheCompanysReceiverOnce(): void
    {
        $this->removeReceivers();
        $twilio = new FakeTwilioNumbersClient();
        $agent = $this->agent(sms: $this->number);

        $result = $this->configure($agent, AgentPhoneChannelEnum::SMS, $twilio);
        $this->configure($agent, AgentPhoneChannelEnum::SMS, $twilio);

        $receivers = $this->receivers()->get();
        $this->assertCount(1, $receivers);
        $this->assertTrue($receivers->first()->is_active);
        $this->assertSame($agent->user->getId(), $receivers->first()->users_id);
        $this->assertSame([
            'user_id' => $agent->user->getId(),
            'agent_id' => $agent->getId(),
            'users_id' => $agent->user->getId(),
        ], $receivers->first()->configuration);
        $this->assertSame('wired', $result['status']);
        $this->assertSame($receivers->first()->getUrl(), $result['url']);
    }

    public function testADeletedReceiverIsRecreatedForTheAgentOnRetry(): void
    {
        $this->removeReceivers();
        $deleted = $this->receiver();
        $deleted->update(['is_deleted' => 1]);
        $agent = $this->agent(sms: $this->number);

        $result = $this->configure($agent, AgentPhoneChannelEnum::SMS, new FakeTwilioNumbersClient());

        $created = $this->receivers()->sole();
        $this->assertNotSame($deleted->getId(), $created->getId());
        $this->assertSame($created->getUrl(), $result['url']);
        $this->assertSame($agent->getId(), $created->configuration['agent_id']);
        $this->assertSame($agent->user->getId(), $created->users_id);
    }

    public function testASwitchedOffReceiverStaysOffAndTheAgentGetsANewOne(): void
    {
        $this->removeReceivers();
        $receiver = $this->receiver();
        $receiver->update(['is_active' => 0]);
        $agent = $this->agent(sms: $this->number);

        $result = $this->configure($agent, AgentPhoneChannelEnum::SMS, new FakeTwilioNumbersClient());

        $created = $this->receivers()->where('is_active', 1)->sole();
        $this->assertSame('wired', $result['status']);
        $this->assertSame($created->getUrl(), $result['url']);
        $this->assertSame($agent->getId(), $created->configuration['agent_id']);
        $this->assertFalse($receiver->fresh()->is_active);
    }

    public function testAVoiceNumberCreatesNoReceiver(): void
    {
        $this->removeReceivers();

        $this->configure($this->agent(voice: $this->number), AgentPhoneChannelEnum::VOICE, new FakeTwilioNumbersClient());

        $this->assertCount(0, $this->receivers()->get());
    }

    public function testAWebhookAlreadyOursIsNotRewritten(): void
    {
        $receiver = $this->receiver();
        $twilio = new FakeTwilioNumbersClient(smsUrl: $receiver->getUrl());

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, $twilio);

        $this->assertSame('wired', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testAForeignWebhookIsLeftInPlaceAndReported(): void
    {
        $this->receiver();
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);
        $agent = $this->agent(sms: $this->number);

        $result = $this->configure($agent, AgentPhoneChannelEnum::SMS, $twilio);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame(self::FOREIGN_URL, $result['url']);
        $this->assertSame([], $twilio->updates);
        $this->assertSame('conflict', $agent->fresh()->sms_config['inbound_webhook']['status']);
    }

    public function testAForeignVoiceWebhookIsAlsoLeftInPlace(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: self::FOREIGN_URL);

        $result = $this->configure($this->agent(voice: $this->number), AgentPhoneChannelEnum::VOICE, $twilio);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testOverwriteReplacesAForeignWebhook(): void
    {
        $receiver = $this->receiver();
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);

        $result = $this->configure(
            $this->agent(sms: $this->number),
            AgentPhoneChannelEnum::SMS,
            $twilio,
            overwrite: true
        );

        $this->assertSame('wired', $result['status']);
        $this->assertSame($receiver->getUrl(), $twilio->updates[0]['smsUrl']);
    }

    public function testTwilioRefusingTheWebhookShowsItsReason(): void
    {
        $this->receiver();
        $twilio = new FakeTwilioNumbersClient();
        $twilio->updateFailure = new RestException('SmsUrl is not valid', 21402, 400);

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, $twilio);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('SmsUrl is not valid', $result['message']);
    }

    public function testTwilioRefusingTheReleaseShowsItsReason(): void
    {
        $twilio = new FakeTwilioNumbersClient(smsUrl: self::FOREIGN_URL);
        $twilio->updateFailure = new RestException('Number is locked', 21400, 400);

        $result = $this->release(AgentPhoneChannelEnum::SMS, $twilio, force: true);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('Number is locked', $result['message']);
    }

    public function testARefusedNumberLeavesNoReceiverBehind(): void
    {
        $this->removeReceivers();

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, new FakeTwilioNumbersClient(sms: false));

        $this->assertSame('error', $result['status']);
        $this->assertCount(0, $this->receivers()->get());
    }

    public function testANumberThatCannotTextIsRefused(): void
    {
        $this->receiver();
        $twilio = new FakeTwilioNumbersClient(sms: false);

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, $twilio);

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $twilio->updates);
    }

    public function testANumberOutsideTheAllowedListIsWiredWithAWarning(): void
    {
        $this->receiver();
        $this->company->set(TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value, '+15550000000');

        $result = $this->configure($this->agent(sms: $this->number), AgentPhoneChannelEnum::SMS, new FakeTwilioNumbersClient());

        $this->assertSame('wired', $result['status']);
        $this->assertStringContainsString('allowed From numbers', $result['warnings'][0]);
    }

    public function testAllowedFromNumbersReadsEveryStoredShape(): void
    {
        $key = TwilioConfigurationEnum::TWILIO_ALLOWED_FROM_PHONE_NUMBERS->value;
        $expected = ['+15550000000', '+15550000001'];

        foreach ([$expected, json_encode($expected), '+15550000000, +15550000001'] as $stored) {
            $this->company->set($key, $stored);
            $this->assertSame($expected, TwilioConfigurationEnum::allowedFromNumbers($this->company));
        }

        $this->company->set($key, '15550000000');
        $this->assertSame(['+15550000000'], TwilioConfigurationEnum::allowedFromNumbers($this->company));

        $this->company->del($key);
        $this->assertSame([], TwilioConfigurationEnum::allowedFromNumbers($this->company));
    }

    public function testAnAgentWithoutTheChannelKeepsANullConfig(): void
    {
        $agent = $this->agent(voice: $this->number);

        $result = $this->configure($agent, AgentPhoneChannelEnum::SMS, new FakeTwilioNumbersClient());

        $this->assertSame('idle', $result['status']);
        $this->assertNull($agent->fresh()->sms_config);
    }

    public function testVoiceReleaseClearsOurWebhook(): void
    {
        $twilio = new FakeTwilioNumbersClient(voiceUrl: VoiceRuntimeConfig::inboundWebhookUrl($this->testApp));

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

        $numbers = new class ($this->company, $twilio) extends ListCompanyPhoneNumbersAction {
            public function __construct(Companies $company, private FakeTwilioNumbersClient $fake)
            {
                parent::__construct($company);
            }

            protected function twilio(): Client
            {
                return $this->fake;
            }
        }->execute();

        $this->assertSame([
            [
                'phone_number' => $this->number,
                'friendly_name' => 'Sales',
                'provider' => 'twilio',
                'provider_id' => 'PNfake',
            ],
        ], $numbers);
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
        AgentPhoneChannelEnum $channel,
        FakeTwilioNumbersClient $twilio,
        bool $overwrite = false
    ): array {
        return new class (
            $agent,
            $channel,
            $overwrite,
            $twilio
        ) extends ConfigureAgentInboundWebhookAction {
            public function __construct(
                Agent $agent,
                AgentPhoneChannelEnum $channel,
                bool $overwrite,
                private FakeTwilioNumbersClient $fake
            ) {
                parent::__construct($agent, $channel, $overwrite);
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
            $this->testApp,
            $this->company,
            $channel,
            $force,
            $twilio
        ) extends ReleaseInboundWebhookForNumberAction {
            public function __construct(
                string $number,
                Apps $app,
                Companies $company,
                AgentPhoneChannelEnum $channel,
                bool $force,
                private FakeTwilioNumbersClient $fake
            ) {
                parent::__construct(
                    number: $number,
                    app: $app,
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

    private function receivers(): Builder
    {
        return ReceiverWebhook::query()
            ->fromApp($this->testApp)
            ->fromCompany($this->company)
            ->forJob(ProcessTwilioWebhookJob::class)
            ->notDeleted();
    }

    private function removeReceivers(): void
    {
        $this->receivers()->update(['is_deleted' => 1]);
    }

    private function receiver(): ReceiverWebhook
    {
        return ReceiverWebhook::factory()->create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'action_id' => WorkflowAction::factory()->create(['model_name' => ProcessTwilioWebhookJob::class])->getId(),
            'is_active' => 1,
        ]);
    }
}
