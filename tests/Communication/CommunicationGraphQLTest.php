<?php

declare(strict_types=1);

namespace Tests\Communication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

final class CommunicationGraphQLTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
    }

    public function testConnectionsAreListedWithTheirChannelsAndState(): void
    {
        $this->defaultSmsConnection(number: '+18095550500');

        $this->graphQL('
            query { communicationConnections(channel: "sms") { account_key provider channels setup_status enabled } }
        ')->assertJson(['data' => ['communicationConnections' => [[
            'account_key' => '+18095550500',
            'provider' => 'fake',
            'channels' => ['sms'],
            'setup_status' => 'ready',
            'enabled' => true,
        ]]]]);
    }

    public function testSendAndReadTheOperationBack(): void
    {
        $this->defaultSmsConnection();
        $person = $this->personWithCellphone('+18095550501');

        $sent = $this->graphQL('
            mutation ($input: CommunicationSendInput!) {
                communicationSend(input: $input) { id status deliveries { channel status to_address } }
            }
        ', ['input' => [
            'people_id' => $person->getId(),
            'content' => ['text' => 'Your order shipped'],
            'channels' => ['sms'],
            'idempotency_key' => 'order:1',
        ]])->assertSuccessful()->json('data.communicationSend');

        $this->assertSame('+18095550501', $sent['deliveries'][0]['to_address']);

        $this->graphQL('query ($id: ID!) { communicationOperation(id: $id) { id deliveries { status } } }', ['id' => $sent['id']])
            ->assertJson(['data' => ['communicationOperation' => ['id' => $sent['id']]]]);
    }

    public function testOnlyAFailedDeliveryIsRetried(): void
    {
        $this->defaultSmsConnection();
        $this->transport->sendResult = TransportResult::failed('30003', 'Unreachable destination handset');

        $operation = $this->communication()->send($this->personWithCellphone(), 'Hello', channels: ['sms'], sync: true);
        $this->transport->sendResult = null;

        $this->graphQL('mutation ($id: ID!) { communicationRetryDelivery(id: $id) { attempts } }', [
            'id' => $operation->deliveries()->first()->getId(),
        ])->assertJson(['data' => ['communicationRetryDelivery' => ['attempts' => 2]]]);
    }

    public function testSwitchingAConnectionOff(): void
    {
        // The switch is admin-only; the suite's user is not one in every environment.
        Bouncer::scope()->to(RolesEnums::getScope($this->currentApp()));
        Bouncer::assign(RolesEnums::ADMIN->value)->to(auth()->user());
        Bouncer::refresh();

        $connection = $this->defaultSmsConnection();

        $this->graphQL('
            mutation ($id: ID!) { communicationConnectionEnabled(id: $id, enabled: false) { enabled } }
        ', ['id' => $connection->getId()])
            ->assertJson(['data' => ['communicationConnectionEnabled' => ['enabled' => false]]]);

        $this->assertFalse($connection->fresh()->enabled);
    }
}
