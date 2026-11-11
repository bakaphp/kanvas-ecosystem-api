<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Twilio\Actions\DescribeCompanyTwilioAction;
use Kanvas\Connectors\Twilio\Actions\ListCompanyPhoneNumbersAction;
use Kanvas\Intelligence\Agents\Actions\Voice\CheckVoiceAgentReadinessAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\VoiceRuntimeConfig;
use Tests\Stubs\Intelligence\FakeTwilioAccountClient;
use Tests\TestCase;
use Twilio\Rest\Client;

class VoiceAgentReadinessTest extends TestCase
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

    public function testAFullySetUpAgentIsReady(): void
    {
        $readiness = $this->readiness($this->agent(), $this->wiredAccount());

        $this->assertTrue($readiness['ready']);
        $this->assertSame(
            array_fill_keys(
                ['runtime', 'twilio', 'balance', 'phone_number', 'inbound', 'prompt', 'call_model', 'voice', 'greeting', 'test_call'],
                'ok'
            ),
            array_column($readiness['checks'], 'status', 'key')
        );
    }

    public function testAnAgentWithoutANumberIsNotReady(): void
    {
        $readiness = $this->readiness($this->agent(voice: ['phone_number' => null]), $this->wiredAccount());

        $this->assertFalse($readiness['ready']);
        $this->assertSame(['error', 'pick_number'], $this->statusAndAction($readiness, 'phone_number'));
        $this->assertNotContains('inbound', array_column($readiness['checks'], 'key'));
    }

    public function testANumberPointingElsewhereNeedsRewiring(): void
    {
        $readiness = $this->readiness($this->agent(), new FakeTwilioAccountClient(voiceUrl: 'https://other-system.test/hook'));

        $this->assertFalse($readiness['ready']);
        $this->assertSame(['error', 'rewire_number'], $this->statusAndAction($readiness, 'inbound'));
    }

    public function testANumberFromAnotherAccountIsNotReady(): void
    {
        $readiness = $this->readiness($this->agent(voice: ['phone_number' => '+12722917870']), $this->wiredAccount(numbers: []));

        $this->assertSame(['error', 'pick_number'], $this->statusAndAction($readiness, 'phone_number'));
    }

    public function testAnEmptyBalanceBlocksCalls(): void
    {
        $client = $this->wiredAccount();
        $client->balance = '0.00';

        $readiness = $this->readiness($this->agent(), $client);

        $this->assertFalse($readiness['ready']);
        $this->assertSame('error', $this->statusAndAction($readiness, 'balance')[0]);
    }

    public function testATrialAccountWarnsButDoesNotBlock(): void
    {
        $client = $this->wiredAccount();
        $client->type = 'Trial';

        $readiness = $this->readiness($this->agent(), $client);

        $this->assertTrue($readiness['ready']);
        $this->assertSame(['warning', 'connect_twilio'], $this->statusAndAction($readiness, 'twilio'));
    }

    public function testMissingVoiceGreetingAndTestCallOnlyWarn(): void
    {
        $agent = $this->agent(voice: ['voice_id' => null, 'greeting' => null, 'last_test_call' => null]);

        $readiness = $this->readiness($agent, $this->wiredAccount());

        $this->assertTrue($readiness['ready']);
        $this->assertSame(['warning', 'edit_voice'], $this->statusAndAction($readiness, 'voice'));
        $this->assertSame(['warning', 'edit_voice'], $this->statusAndAction($readiness, 'greeting'));
        $this->assertSame(['warning', 'test_call'], $this->statusAndAction($readiness, 'test_call'));
    }

    public function testARemovedCallModelFallsBackWithAWarning(): void
    {
        $readiness = $this->readiness($this->agent(voice: ['llm_config_id' => 999999999]), $this->wiredAccount());

        $this->assertTrue($readiness['ready']);
        $this->assertSame('warning', $this->statusAndAction($readiness, 'call_model')[0]);
    }

    public function testAnUnconfiguredRuntimeIsNotReady(): void
    {
        config(['kanvas.voice_runtime.api_token' => '']);
        if (VoiceRuntimeConfig::apiToken($this->testApp) !== '') {
            $this->markTestSkipped('The test app sets its own runtime token.');
        }

        $readiness = $this->readiness($this->agent(), $this->wiredAccount());

        $this->assertFalse($readiness['ready']);
        $this->assertSame('error', $this->statusAndAction($readiness, 'runtime')[0]);
    }

    public function testTheQueryAnswersForTheActingCompanysAgent(): void
    {
        $agent = $this->agent();

        $response = $this->graphQL('
            query($id: ID!) {
                voiceAgentReadiness(id: $id) {
                    ready
                    checks { key label status message action }
                }
            }
        ', ['id' => $agent->getId()]);

        $this->assertNull($response->json('errors'), (string) json_encode($response->json('errors')));
        $keys = array_column($response->json('data.voiceAgentReadiness.checks'), 'key');
        $this->assertContains('runtime', $keys);
        $this->assertContains('twilio', $keys);
    }

    private function wiredAccount(?array $numbers = null): FakeTwilioAccountClient
    {
        $client = new FakeTwilioAccountClient(voiceUrl: VoiceRuntimeConfig::inboundWebhookUrl($this->testApp));
        if ($numbers !== null) {
            $client->numbers = $numbers;
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $voice merged over a fully set-up voice_config
     */
    private function agent(array $voice = []): Agent
    {
        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'instructions' => 'You are Sally, the dealership receptionist.',
                'voice_config' => array_merge([
                    'phone_number' => FakeTwilioAccountClient::NUMBER,
                    'voice_id' => 'cryPG4aBcWsmW2cCIXjB',
                    'greeting' => 'Greet the caller.',
                    'last_test_call' => ['at' => '2026-11-11T10:00:00-04:00', 'call_id' => 'call_1'],
                ], $voice),
            ]);
    }

    /**
     * @return array{ready: bool, checks: list<array<string, mixed>>}
     */
    private function readiness(Agent $agent, FakeTwilioAccountClient $client): array
    {
        $account = new class ($this->company, $client) extends ListCompanyPhoneNumbersAction {
            public function __construct(Companies $company, private FakeTwilioAccountClient $client)
            {
                parent::__construct($company);
            }

            protected function twilio(): Client
            {
                return $this->client;
            }
        };

        $twilio = new class ($this->company, $account) extends DescribeCompanyTwilioAction {
            public function __construct(Companies $company, private ListCompanyPhoneNumbersAction $fake)
            {
                parent::__construct($company);
            }

            protected function account(): ListCompanyPhoneNumbersAction
            {
                return $this->fake;
            }
        };

        return new class ($agent, $twilio, $account) extends CheckVoiceAgentReadinessAction {
            public function __construct(
                Agent $agent,
                private DescribeCompanyTwilioAction $fakeTwilio,
                private ListCompanyPhoneNumbersAction $fakeNumbers
            ) {
                parent::__construct($agent);
            }

            protected function twilio(): DescribeCompanyTwilioAction
            {
                return $this->fakeTwilio;
            }

            protected function phoneNumbers(): ListCompanyPhoneNumbersAction
            {
                return $this->fakeNumbers;
            }
        }->execute();
    }

    /**
     * @param array{checks: list<array<string, mixed>>} $readiness
     *
     * @return array{0: string, 1: string|null}
     */
    private function statusAndAction(array $readiness, string $key): array
    {
        $check = array_column($readiness['checks'], null, 'key')[$key];

        return [$check['status'], $check['action']];
    }
}
