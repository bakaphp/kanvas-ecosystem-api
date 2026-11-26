<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Messages\Actions\LinkDeliveryMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Twilio\Enums\ConfigurationEnum as TwilioConfigurationEnum;
use Kanvas\Guild\Customers\Enums\ConsentConfigurationEnum;
use Kanvas\Guild\Leads\Actions\Communication\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Social\Messages\Models\Message;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

/**
 * A text to a lead leaves through the company's connection and is recorded on the delivery ledger;
 * a company with no number connected does not text.
 */
final class LeadSmsThroughCommunicationTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string COMPANY_NUMBER = '+18095550800';
    private const string CUSTOMER = '+18095550801';

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
        $company = $this->currentCompany();
        $company->set(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value, 'ACtest');
        $company->set(TwilioConfigurationEnum::TWILIO_AUTH_TOKEN->value, 'token');
        $company->set(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value, self::COMPANY_NUMBER);
    }

    /**
     * Company settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        $company = $this->currentCompany();
        $company->del(TwilioConfigurationEnum::TWILIO_ACCOUNT_SID->value);
        $company->del(TwilioConfigurationEnum::TWILIO_AUTH_TOKEN->value);
        $company->del(TwilioConfigurationEnum::TWILIO_FROM_PHONE_NUMBER->value);

        parent::tearDown();
    }

    public function testATextLeavesThroughTheConnectionAndIsOnTheLedgerOnly(): void
    {
        $connection = $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $lead = $this->lead();

        $result = $this->action($lead)->execute('sms', 'Your car is ready');

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame(self::CUSTOMER, $delivery->to_address);
        $this->assertSame('Your car is ready', $this->transport->sent[0]['text']);
        $this->assertSame('FAKE-1', $result['provider_message_id']);
        $this->assertSame('FAKE-1', $result['messages'][0]['sid']);
        $this->assertSame($delivery->request_id, $result['operation_id']);
        $this->assertNull($delivery->message_id, 'the caller files the message');
    }

    public function testTheCallersMessageIsLinkedToTheDelivery(): void
    {
        $connection = $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $lead = $this->lead();
        $result = $this->action($lead)->execute('sms', 'Your car is ready');
        $message = $this->fileInConversation(
            $this->conversationChannel($lead),
            $lead,
            'twilio-sms',
            ['content' => 'Your car is ready', 'from_me' => true]
        );

        new LinkDeliveryMessageAction($message)->execute($result);

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->sole();

        $this->assertSame($message->getId(), (int) $delivery->message_id);
        $this->assertSame('FAKE-1', Message::query()->findOrFail($message->getId())->message['delivery']['message_sid'] ?? null);
    }

    public function testAnAgentsTextLeavesFromTheNumberTheAgentOwns(): void
    {
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $agentNumber = '+18095550802';
        $agent = Agent::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['sms_config' => ['phone_number' => $agentNumber]]);
        $owned = $this->connection('fake', $agentNumber);
        $owned->forceFill(['config' => ['agent_id' => $agent->getId()]])->saveOrFail();

        $this->action($this->lead())->execute('sms', 'Hi from Sofia', fromAgent: $agent);

        $this->assertTrue(Delivery::query()->where('connection_id', $owned->getId())->exists());
    }

    public function testACompanyWithNoNumberConnectedDoesNotText(): void
    {
        $result = $this->action($this->lead())->execute('sms', 'Your car is ready');

        $this->assertFalse($result['success']);
        $this->assertSame('The company has no number connected to send texts from', $result['error']);
        $this->assertSame([], $this->transport->sent);
    }

    /**
     * A rule or a caller can carry a number the company no longer has; the text still goes out.
     */
    public function testANamedNumberThatIsNotAConnectionFallsBackToTheDefault(): void
    {
        $connection = $this->defaultSmsConnection(number: self::COMPANY_NUMBER);

        $this->action($this->lead())->execute('sms', 'Your car is ready', '+18095559999');

        $this->assertTrue(Delivery::query()->where('connection_id', $connection->getId())->exists());
    }

    public function testARecipientWhoUnsubscribedWithTheCarrierIsReportedAsOptedOut(): void
    {
        $this->transport->sendResult = TransportResult::failed('21610', 'opted_out: unsubscribed', recipientOptedOut: true);
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);

        $result = $this->action($this->lead())->execute('sms', 'Your car is ready');

        $this->assertFalse($result['success']);
        $this->assertTrue($result['opted_out']);
        $this->assertSame('21610', $result['provider_error_code']);
        $this->assertSame('opted_out', $result['classification']);
    }

    /**
     * The one text a person who asked not to be contacted still gets, and it is tagged as such.
     */
    public function testTheOptOutConfirmationReachesAPersonWhoAskedNotToBeContacted(): void
    {
        $connection = $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $lead = $this->lead();
        $lead->people->set(ConsentConfigurationEnum::DO_NOT_CONTACT->value, true);

        $refused = $this->action($lead)->execute('sms', 'Your car is ready');
        $this->assertFalse($refused['success']);
        $this->assertSame([], $this->transport->sent);

        $result = $this->action($lead)->allowOptOutConfirmation()->execute('sms', 'You have been unsubscribed.');

        $delivery = Delivery::query()->where('connection_id', $connection->getId())->latest('id')->firstOrFail();

        $this->assertSame('FAKE-1', $result['provider_message_id']);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame(Communication::OPT_OUT_CONFIRMATION, $delivery->request->tag);
    }

    public function testAMissingPersonIsAValidationFailureOnEveryChannel(): void
    {
        $lead = $this->lead();
        $lead->setRelation('people', null);

        foreach (['sms', 'whatsapp', 'email'] as $channel) {
            $result = $this->action($lead)->execute($channel, 'Hello');

            $this->assertFalse($result['success']);
            $this->assertSame('validation_failed', $result['classification']);
            $this->assertFalse($result['retryable']);
        }

        $this->assertSame([], $this->transport->sent);
    }

    private function lead(): Lead
    {
        return $this->leadOf($this->personWithCellphone(self::CUSTOMER));
    }

    private function action(Lead $lead): SendMessageToLeadAction
    {
        return new SendMessageToLeadAction($lead);
    }
}
