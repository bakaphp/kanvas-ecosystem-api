<?php

declare(strict_types=1);

namespace Tests\Communication;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SendMessageTool;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

final class SendMessageToolTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
        $this->defaultSmsConnection();
    }

    public function testTheAgentNamesThePersonAndChannelAndTheAddressComesFromTheirContacts(): void
    {
        $person = $this->personWithCellphone('+18095550600');

        $result = $this->tool()($person->getId(), 'sms', 'Your appointment is confirmed');

        $this->assertSame('success', $result['status']);
        $this->assertSame('accepted', $result['deliveries'][0]['status']);
        $this->assertSame('+18095550600', $this->transport->sent[0]['to']);
    }

    public function testAPersonWhoAskedNotToBeContactedIsNotMessaged(): void
    {
        $person = $this->personWithCellphone();
        $person->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, true);

        $result = $this->tool()($person->getId(), 'sms', 'Hello');

        $this->assertSame('do_not_contact', $result['deliveries'][0]['reason']);
        $this->assertSame([], $this->transport->sent);
    }

    public function testAnIdFromAnotherCompanyResolvesToNothing(): void
    {
        $result = $this->tool()(PHP_INT_MAX, 'sms', 'Hello');

        $this->assertArrayHasKey('error', $result);
        $this->assertSame([], $this->transport->sent);
    }

    private function tool(): SendMessageTool
    {
        return new SendMessageTool()->withContext($this->currentApp(), $this->currentCompany(), auth()->user());
    }
}
