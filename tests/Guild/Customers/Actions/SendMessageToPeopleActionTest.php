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
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
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
        $this->assertSame(
            'fake-18095550811',
            $result['channel_slug'],
            'the channel the vendor keeps the conversation on'
        );
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

    /**
     * A vendor that cannot carry a file sends the text and says so. The caller is told, because a
     * document link that arrived without its PDF is a different outcome from one that arrived whole.
     */
    public function testWhatTheProviderLeftOutOfAnAcceptedMessageIsReported(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $transport->sendResult = TransportResult::accepted('FAKE-partial', ['attachments_dropped']);

        $result = new SendMessageToPeopleAction($this->personWithCellphone())->execute(
            channel: ChannelTypeEnum::SMS,
            to: '+18095550199',
            message: 'Here is your invoice',
            attachmentUrls: ['https://cdn.kanvas.test/invoice.pdf'],
        );

        $this->assertSame(['attachments_dropped'], $result['warnings']);
    }

    public function testWithNoAddressNamedItGoesToThePersonsOwnNumber(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);

        new SendMessageToPeopleAction($this->personWithCellphone('+18095550812'))->execute(
            channel: ChannelTypeEnum::SMS,
            to: null,
            message: 'Hello',
        );

        $this->assertSame('+18095550812', $transport->sent[0]['to']);
    }

    /**
     * With no number named, the person's cellphones are tried by priority, and one they stopped is
     * passed over for the next.
     */
    public function testWithNoAddressNamedTheHighestPriorityNumberThatCanBeWrittenToIsUsed(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $person = $this->personWithCellphone('+18095550816');
        $addCellphone = fn (string $number, int $weight, bool $stopped = false) => $person
            ->contacts()
            ->create([
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'value' => $number,
                'weight' => $weight,
                'is_opt_out' => $stopped ? 1 : 0,
            ]);
        $addCellphone('+18095550817', 20, stopped: true);
        $addCellphone('+18095550818', 10);
        $send = new SendMessageToPeopleAction($person);

        $send->execute(channel: ChannelTypeEnum::SMS, to: null, message: 'Hello');

        $this->assertSame('+18095550818', $transport->sent[0]['to'], 'the stopped number is passed over');

        $person->contacts()->where('weight', 20)->update(['is_opt_out' => 0]);

        $this->assertSame(
            '+18095550817',
            $send->phoneNumber((string) $send->addressFor(ChannelTypeEnum::SMS), ChannelTypeEnum::SMS),
            'highest priority first'
        );
    }

    public function testANumberThePersonStoppedIsNotWrittenTo(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $person = $this->personWithCellphone('+18095550813');
        $person->contacts()->update(['is_opt_out' => 1]);

        try {
            new SendMessageToPeopleAction($person)->execute(
                channel: ChannelTypeEnum::SMS,
                to: '+18095550813',
                message: 'Hello',
            );
            $this->fail('An address the person opted out on must not be written to.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('opted out', $exception->getMessage());
        }

        $this->assertSame([], $transport->sent);
    }

    /**
     * On a company of its own: the setting would reroute every text the shared test company sends.
     */
    public function testACompanyTestingSendsEveryTextToItsTestNumber(): void
    {
        $company = Companies::factory()->create();
        $person = People::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($company->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
        $send = new SendMessageToPeopleAction($person);

        $this->assertSame('+18095550814', $send->phoneNumber('(809) 555-0814', ChannelTypeEnum::SMS));

        $company->set('allow_session_hijack', true);
        $company->set('overwrite_phone_number', ['twilio-+18095550999' => '+18095550814']);

        $this->assertSame('+18095550999', $send->phoneNumber('+18095550814', ChannelTypeEnum::SMS));
    }

    public function testATextWithMoreFilesThanOneMessageCarriesGoesOutInBatchesAndStopsAtAFailure(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $send = new SendMessageToPeopleAction($this->personWithCellphone('+18095550815'));
        $files = array_map(fn (int $number): string => "https://cdn.kanvas.test/{$number}.jpg", range(1, 9));
        $batches = $send->mediaBatches($files);

        $deliveries = $send->deliverText('+18095550815', 'Photos of the car', $batches);

        $this->assertSame([8, 1], array_map('count', $batches));
        $this->assertCount(2, $deliveries);
        $this->assertSame(
            ['Photos of the car', ''],
            array_column($transport->sent, 'text'),
            'the words go once'
        );

        $transport->sendResult = TransportResult::failed('rejected_by_provider', 'No');
        $stopped = $send->deliverText('+18095550815', 'Again', $batches);

        $this->assertCount(1, $stopped, 'nothing is sent after the message that failed');
        $this->assertFalse($stopped[0]->wasAccepted());
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

    public function testAPersonWithNoAddressOfThatKindThrowsBeforeAnythingIsSent(): void
    {
        $transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: self::COMPANY_NUMBER);
        $person = $this->personWithCellphone();
        $person->contacts()->delete();

        try {
            new SendMessageToPeopleAction($person)->execute(
                channel: ChannelTypeEnum::SMS,
                to: '   ',
                message: 'Hello',
            );
            $this->fail('A person with no number has nowhere to be texted.');
        } catch (ValidationException $exception) {
            $this->assertSame('People has no sms address to send to', $exception->getMessage());
        }

        $this->assertSame([], $transport->sent);
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
