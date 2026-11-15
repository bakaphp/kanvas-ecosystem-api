<?php

declare(strict_types=1);

namespace Tests\Guild\Customers\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Companies\Models\Companies;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Actions\Communication\SendMessageToPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

/**
 * A person with no lead is written to in one place, on any channel, through the company's
 * connection for that channel; a message that did not leave throws.
 */
final class SendMessageToPeopleActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social', 'intelligence'];

    private const string COMPANY_NUMBER = '+18095550810';

    public function testATextLeavesOnTheCompanysConnectionForTheChannelWithItsAttachments(): void
    {
        $transport = $this->useFakeProvider();
        $connection = $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $person = $this->personWithCellphone('+18095550811');

        $result = new SendMessageToPeopleAction($person)->execute(
            channel: ChannelTypeEnum::SMS,
            to: '(809) 555-0811',
            message: 'Here is your invoice',
            attachmentUrls: ['https://cdn.kanvas.test/invoice.pdf'],
        );

        $this->assertCount(1, $transport->sent);
        $this->assertSame('+18095550811', $transport->sent[0]['to'], 'a number is sent as E.164');
        $this->assertSame('Here is your invoice', $transport->sent[0]['text']);
        $this->assertSame(
            'https://cdn.kanvas.test/invoice.pdf',
            $transport->sent[0]['content']->attachments[0]->url
        );

        $delivery = Delivery::query()->where('external_id', $result['provider_message_id'])->sole();
        $this->assertSame($connection->getId(), (int) $delivery->connection_id);
        $this->assertSame($person->getId(), (int) $delivery->request->people_id);
        $this->assertSame(
            [
                'channel' => 'sms',
                'to' => '+18095550811',
                'from' => self::COMPANY_NUMBER,
                'people_id' => $person->getId(),
                'provider' => 'fake',
            ],
            array_intersect_key($result, array_flip(['channel', 'to', 'from', 'people_id', 'provider']))
        );
    }

    public function testAMessageTheProviderRefusedThrows(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $transport->sendResult = TransportResult::failed('rejected_by_provider', 'The number is not reachable');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('The message was not sent: The number is not reachable');

        new SendMessageToPeopleAction($this->personWithCellphone())->execute(
            channel: ChannelTypeEnum::SMS,
            to: '+18095550199',
            message: 'Hello',
        );
    }

    public function testAChannelTheCompanyHasNoConnectionForThrowsAndSendsNothing(): void
    {
        $transport = $this->useFakeProvider();

        try {
            new SendMessageToPeopleAction($this->personWithCellphone())->execute(
                channel: ChannelTypeEnum::WHATSAPP,
                to: '+18095550199',
                message: 'Hello',
            );
            $this->fail('A company with no WhatsApp connection must not report a sent message.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('not sent', $exception->getMessage());
        }

        $this->assertSame([], $transport->sent);
    }

    public function testAnEmptyAddressThrowsBeforeAnythingIsSent(): void
    {
        $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('People has no sms address to send to');

        new SendMessageToPeopleAction($this->personWithCellphone())->execute(
            channel: ChannelTypeEnum::SMS,
            to: '   ',
            message: 'Hello',
        );
    }

    public function testAnEmailIsWrittenByTheEmailActionAndLeavesThroughTheSameSend(): void
    {
        Notification::fake();
        $person = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId(Companies::factory()->create()->getId())
            ->withUserId(auth()->user()->getId())
            ->create();

        $result = new SendMessageToPeopleAction($person)->execute(
            channel: ChannelTypeEnum::EMAIL,
            to: 'ana@example.test',
            message: 'Here is your invoice',
            subject: 'Your invoice',
        );

        $this->assertSame('email', $result['channel']);
        $this->assertSame('ana@example.test', $result['to']);
        $this->assertSame('first-time-agent-engagement', $result['template']);
        $this->assertNotNull($result['provider_message_id']);
    }
}
