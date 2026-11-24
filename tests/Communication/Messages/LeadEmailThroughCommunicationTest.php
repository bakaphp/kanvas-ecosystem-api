<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Channels\Email\EmailComposer;
use Kanvas\Communication\Channels\Email\Mail\MailCarrier;
use Kanvas\Communication\Communication;
use Kanvas\Communication\Connections\Actions\SetConnectionEnabledAction;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\EmailOptions;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Actions\Communication\SendMessageToLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Notifications\Support\UserEmailSignature;
use Kanvas\Notifications\Templates\Blank;
use Mockery;
use Tests\TestCase;

/**
 * A company that connected no email provider still sends: the Kanvas mailer is a connection made on the
 * first email, and the email is on the ledger like every other message.
 */
final class LeadEmailThroughCommunicationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string CUSTOMER = 'prospect@example.com';

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.default' => 'sync']);
        Notification::fake();
    }

    /**
     * User settings outlive the test transaction (Redis and the ecosystem connection).
     */
    protected function tearDown(): void
    {
        auth()->user()->del(UserEmailSignature::SETTING);

        parent::tearDown();
    }

    public function testAnEmailLeavesOnTheKanvasMailerAndIsOnTheLedger(): void
    {
        $lead = $this->lead();

        $result = new SendMessageToLeadAction($lead)->execute(
            channel: 'email',
            message: 'Here is your **quote**.',
            title: 'Your quote',
            cc: ['manager@example.com'],
        );

        $connection = $this->mailConnection();
        $delivery = $this->deliveriesOn($connection)->sole();

        $this->assertTrue($connection->isBuiltIn());
        $this->assertNull($connection->unavailableReason());
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertSame(self::CUSTOMER, $delivery->to_address);
        $this->assertSame(MailCarrier::slug(), $result['provider']);
        $this->assertSame($delivery->external_id, $result['provider_message_id']);
        $this->assertSame($delivery->request_id, $result['operation_id']);
        $this->assertSame(EmailComposer::DEFAULT_TEMPLATE, $result['template']);
        $this->assertNull($delivery->message_id, 'the caller files the message');

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification, array $channels, AnonymousNotifiable $notifiable) use ($delivery, $lead): bool {
                $data = $notification->getData();

                return $notifiable->routes['mail'] === self::CUSTOMER
                    && $notification->getTemplateName() === EmailComposer::DEFAULT_TEMPLATE
                    && $notification->getMessageId() === $delivery->external_id
                    && $notification->getCc() === ['manager@example.com']
                    && str_contains($data['content'], '<strong>quote</strong>')
                    && $data['lead']->is($lead)
                    && $data['noHi'] === true
                    && $data['signature'] === true;
            }
        );
    }

    public function testEveryEmailUsesTheSameConnection(): void
    {
        $lead = $this->lead();

        new SendMessageToLeadAction($lead)->execute(channel: 'email', message: 'First');
        new SendMessageToLeadAction($lead)->execute(channel: 'email', message: 'Second');

        $this->assertSame(2, $this->deliveriesOn($this->mailConnection())->count());
    }

    public function testASwitchedOffConnectionSendsNothing(): void
    {
        $lead = $this->lead();
        $communication = Communication::for($lead->app, $lead->company);
        new SetConnectionEnabledAction($communication->connectionFor(ChannelTypeEnum::EMAIL), false)->execute();

        $result = new SendMessageToLeadAction($lead)->execute(channel: 'email', message: 'Hello');

        $this->assertFalse($result['success']);
        $this->assertSame('connection_disabled', $result['provider_error_code']);
        Notification::assertNothingSent();
    }

    public function testATemplateTheCompanyNeverGotFallsBackToTheDefault(): void
    {
        $result = new SendMessageToLeadAction($this->lead())
            ->withEmailTemplate('a-template-nobody-made')
            ->execute(channel: 'email', message: 'Hello');

        $this->assertSame(EmailComposer::DEFAULT_TEMPLATE, $result['template']);
        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getTemplateName() === EmailComposer::DEFAULT_TEMPLATE
        );
    }

    public function testAnAssistantGrantedSigningClosesWithItsOwnersSignatureOnly(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => true, 'text' => "Owner Name\nSales Manager"]);

        new SendMessageToLeadAction($this->lead())
            ->withSignatureOwner(auth()->user())
            ->execute(
                channel: 'email',
                message: 'Hello',
                fromAgent: $this->agent(internal: true, signs: true),
            );

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['signature'] === false
                && str_contains((string) $notification->getData()['user_signature'], 'Sales Manager')
        );
    }

    public function testACustomerFacingAgentKeepsTheCompanySignOff(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => true, 'text' => 'Owner Name']);

        new SendMessageToLeadAction($this->lead())
            ->withSignatureOwner(auth()->user())
            ->execute(
                channel: 'email',
                message: 'Hello',
                fromAgent: $this->agent(internal: false, signs: false),
            );

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['signature'] === true
                && ! isset($notification->getData()['user_signature'])
        );
    }

    public function testAnAssistantWithoutTheGrantSendsNoSignatureAtAll(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => true, 'text' => 'Owner Name']);

        new SendMessageToLeadAction($this->lead())
            ->withSignatureOwner(auth()->user())
            ->execute(
                channel: 'email',
                message: 'Hello',
                fromAgent: $this->agent(internal: true, signs: false),
            );

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['signature'] === false
                && ! isset($notification->getData()['user_signature'])
        );
    }

    public function testAnEmailAPersonWroteClosesWithTheirOwnSignature(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => true, 'text' => "Sales Rep\nInternet Sales"]);

        new SendMessageToLeadAction($this->lead())
            ->sentByUser(auth()->user())
            ->execute(channel: 'email', message: 'Hello', signature: false);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['signature'] === false
                && str_contains((string) $notification->getData()['user_signature'], 'Internet Sales')
        );
    }

    public function testAPersonWhoSwitchedTheirSignatureOffSendsNone(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => false, 'text' => 'Sales Rep']);

        new SendMessageToLeadAction($this->lead())
            ->sentByUser(auth()->user())
            ->execute(channel: 'email', message: 'Hello', signature: false);

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => ! isset($notification->getData()['user_signature'])
        );
    }

    public function testASignatureOwnerWithNoAgentAndNoAuthorStillSendsNoOwnerSignature(): void
    {
        auth()->user()->set(UserEmailSignature::SETTING, ['append' => true, 'text' => 'Owner Name']);

        new SendMessageToLeadAction($this->lead())
            ->withSignatureOwner(auth()->user())
            ->execute(channel: 'email', message: 'Hello');

        Notification::assertSentOnDemand(
            Blank::class,
            fn (Blank $notification): bool => $notification->getData()['signature'] === true
                && ! isset($notification->getData()['user_signature'])
        );
    }

    public function testTheOptionsSurviveTheRequestTheyAreStoredOn(): void
    {
        $options = new EmailOptions(
            cc: ['manager@example.com'],
            template: 'quote',
            templateData: ['noHi' => true],
            companySignature: true,
            signatureUserId: 7,
            leadId: 9,
            campaignRecipientId: 11,
            trackOpens: true
        );

        $restored = Content::fromArray(Content::text('Hello')->withEmail($options)->toArray());

        $this->assertEquals($options, $restored->email);
    }

    /**
     * Narrowed to this test's customer: other suites email from the same company's connection.
     */
    private function deliveriesOn(Connection $connection): Builder
    {
        return Delivery::query()
            ->where('connection_id', $connection->getId())
            ->where('to_address', self::CUSTOMER);
    }

    private function mailConnection(): Connection
    {
        return Connection::query()
            ->fromApp(app(Apps::class))
            ->fromCompany(auth()->user()->getCurrentCompany())
            ->where('provider', MailCarrier::slug())
            ->sole();
    }

    private function agent(bool $internal, bool $signs): Agent
    {
        $agent = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create(['user_id' => auth()->user()->getId()]);

        $partial = Mockery::mock($agent)->makePartial();
        $partial->shouldReceive('conversesWithUser')->andReturn($internal);
        $partial->shouldReceive('signsEmailsAsOwner')->andReturn($signs);

        return $partial;
    }

    private function lead(): Lead
    {
        $lead = Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(auth()->user()->getCurrentCompany()->getId())
            ->create();

        $lead->people->contacts()
            ->whereIn('contacts_types_id', [
                ContactTypeEnum::PRIMARY_EMAIL->value,
                ContactTypeEnum::EMAIL->value,
                ContactTypeEnum::SECONDARY_EMAIL->value,
            ])
            ->delete();
        $lead->people->addEmail(self::CUSTOMER);

        return $lead;
    }
}
