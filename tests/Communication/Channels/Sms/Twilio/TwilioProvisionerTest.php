<?php

declare(strict_types=1);

namespace Tests\Communication\Channels\Sms\Twilio;

use Baka\Contracts\AppInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\TwilioProvisioner;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Companies\Models\Companies;
use Mockery;
use Mockery\MockInterface;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;
use Twilio\Rest\Client as TwilioClient;

final class TwilioProvisionerTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private const string OURS = 'https://kanvas.test/v1/receiver/ours';

    private MockInterface $client;

    /** @var array<string, string> sid → the inbound URL Twilio holds */
    private array $twilio = [];

    /** @var list<string> */
    private array $updates = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://kanvas.test']);
        $this->client = Mockery::mock(TwilioClient::class);
        $this->client->shouldReceive('getAccountSid')->andReturn('AC-test');
    }

    public function testANumberIsPointedAtTheReceiverAndReadBack(): void
    {
        $this->number('PN1', '');
        $connection = $this->connection('twilio', '+18095550200', ['config' => ['sid' => 'PN1']]);

        $registration = $this->provisioner()->registerInbound($connection, self::OURS);

        $this->assertSame(self::OURS, $this->twilio['PN1']);
        $this->assertSame(['PN1'], $this->updates);
        $this->assertSame(self::OURS, $registration->owned[0]->expectedValue);
    }

    /**
     * A number already pointed at one of our receivers — an older one, set by hand — is ours to move.
     * Anything else is the customer's own and is left alone.
     */
    public function testANumberAnsweringSomewhereElseIsBlockedNotReplaced(): void
    {
        $this->number('PN2', 'https://their-crm.example.com/sms');
        $connection = $this->connection('twilio', '+18095550201', ['config' => ['sid' => 'PN2']]);

        try {
            $this->provisioner()->registerInbound($connection, self::OURS);
            $this->fail('A foreign registration was replaced.');
        } catch (SetupBlockedException $blocked) {
            $this->assertSame('foreign_registration', $blocked->reason);
        }

        $this->assertSame([], $this->updates);
    }

    public function testAnOldKanvasReceiverIsMovedToTheCurrentOne(): void
    {
        $this->number('PN3', 'https://kanvas.test/v1/receiver/legacy-by-hand');
        $connection = $this->connection('twilio', '+18095550202', ['config' => ['sid' => 'PN3']]);

        $this->provisioner()->registerInbound($connection, self::OURS);

        $this->assertSame(self::OURS, $this->twilio['PN3']);
    }

    public function testNumbersInOneMessagingServiceShareOneRegistration(): void
    {
        $this->service('MG1', '');
        $first = $this->connection('twilio', '+18095550203', ['config' => ['messaging_service_sid' => 'MG1']]);
        $second = $this->connection('twilio', '+18095550204', ['config' => ['messaging_service_sid' => 'MG1']]);

        $this->provisioner()->registerInbound($first, self::OURS);
        $registration = $this->provisioner()->registerInbound($second, self::OURS)->shared;

        $this->assertSame(['MG1'], $this->updates, 'the service is set once');
        $this->assertSame(1, SharedRegistration::query()->where('resource_key', 'MG1')->count());
        $this->assertSame(2, $registration->liveDependents()->count());
    }

    public function testCleanupLeavesANumberANewOwnerTookAlone(): void
    {
        $this->number('PN4', 'https://kanvas.test/v1/receiver/new-owner');
        $connection = $this->connection('twilio', '+18095550205');

        $task = CleanupTask::query()->create([
            'apps_id' => $connection->apps_id,
            'companies_id' => $connection->companies_id,
            'connection_id' => $connection->getId(),
            'resource_type' => TwilioProvisioner::NUMBER_SMS_URL,
            'resource_key' => $connection->account_key,
            'external_id' => 'PN4',
            'expected_value' => self::OURS,
        ]);

        $this->assertSame(CleanupTaskStatusEnum::SUPERSEDED, $this->provisioner()->cleanup($task));
        $this->assertSame([], $this->updates);
    }

    private function number(string $sid, string $smsUrl): void
    {
        $this->twilio[$sid] = $smsUrl;

        $context = Mockery::mock();
        $context->shouldReceive('fetch')->andReturnUsing(fn () => (object) ['smsUrl' => $this->twilio[$sid]]);
        $context->shouldReceive('update')->andReturnUsing(function (array $values) use ($sid) {
            $this->updates[] = $sid;
            $this->twilio[$sid] = $values['smsUrl'];

            return (object) $values;
        });

        $this->client->shouldReceive('incomingPhoneNumbers')->with($sid)->andReturn($context);
    }

    private function service(string $sid, string $inboundUrl): void
    {
        $this->twilio[$sid] = $inboundUrl;

        $context = Mockery::mock();
        $context->shouldReceive('fetch')->andReturnUsing(fn () => (object) ['inboundRequestUrl' => $this->twilio[$sid]]);
        $context->shouldReceive('update')->andReturnUsing(function (array $values) use ($sid) {
            $this->updates[] = $sid;
            $this->twilio[$sid] = $values['inboundRequestUrl'];

            return (object) $values;
        });

        $v1 = Mockery::mock();
        $v1->shouldReceive('services')->with($sid)->andReturn($context);
        $this->client->messaging = (object) ['v1' => $v1];
    }

    private function provisioner(): TwilioProvisioner
    {
        return new class ($this->client, new SharedRegistry()) extends TwilioProvisioner {
            public function __construct(
                private readonly TwilioClient $twilio,
                SharedRegistry $sharedRegistry
            ) {
                parent::__construct($sharedRegistry);
            }

            protected function client(Companies $company, AppInterface $app): TwilioClient
            {
                return $this->twilio;
            }
        };
    }
}
