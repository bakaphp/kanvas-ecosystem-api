<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Kanvas\Communication\Messages\Actions\LinkDeliveryMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\TransportResult;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Jobs\DispatchDeliveryJob;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Connectors\Twilio\Actions\ResendFailedMessageAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\Communication\Fixtures\FakeTransport;
use Tests\TestCase;

final class ResendFailedMessageActionTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'social', 'crm', 'workflow'];

    private FakeTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport = $this->useFakeProvider();
        $this->defaultSmsConnection(number: '+18095550970');
    }

    public function testAFailedMessageIsSentAgainAsTheSameDelivery(): void
    {
        [$message, $delivery] = $this->sentMessage(TransportResult::failed('30003', 'Unreachable destination handset'));
        Queue::fake();

        new ResendFailedMessageAction($message)->execute();

        $this->assertSame(DeliveryStatusEnum::PENDING, $delivery->fresh()->status);
        Queue::assertPushed(DispatchDeliveryJob::class, fn (DispatchDeliveryJob $job): bool => $job->delivery->is($delivery));
    }

    public function testTheResentTextDoesNotFileASecondMessage(): void
    {
        [$message, $delivery] = $this->sentMessage(TransportResult::failed('30003', 'Unreachable destination handset'));
        $this->transport->sendResult = null;
        config(['queue.default' => 'sync']);
        $messagesBefore = Message::query()->fromCompany($this->currentCompany())->count();

        new ResendFailedMessageAction($message)->execute();

        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->fresh()->status);
        $this->assertSame($message->getId(), (int) $delivery->fresh()->message_id);
        $this->assertSame($messagesBefore, Message::query()->fromCompany($this->currentCompany())->count());
        $this->assertSame('accepted', Message::query()->findOrFail($message->getId())->message['delivery']['status'] ?? null);
    }

    public function testRefusesAMessageThatDidNotFail(): void
    {
        [$message] = $this->sentMessage(null);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Only a failed or undelivered message can be resent; this one is accepted');

        new ResendFailedMessageAction($message)->execute();
    }

    public function testRefusesAMessageWithNoDelivery(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('no delivery on record');

        new ResendFailedMessageAction($this->message())->execute();
    }

    public function testASecondResendWhileTheFirstIsRunningIsRefused(): void
    {
        [$message] = $this->sentMessage(TransportResult::failed('30003', 'Unreachable destination handset'));
        $lock = Cache::lock('twilio:resend:' . $message->getId(), 60);
        $lock->get();

        try {
            $this->expectException(ValidationException::class);
            $this->expectExceptionMessage('already being resent');

            new ResendFailedMessageAction($message)->execute();
        } finally {
            $lock->release();
        }
    }

    public function testMutationRefusesAMessageThatDidNotFail(): void
    {
        [$message] = $this->sentMessage(null);

        $response = $this->graphQL('
            mutation ($id: ID!) {
                twilioResendMessage(message_id: $id) { id }
            }
        ', ['id' => $message->getId()]);

        $this->assertStringContainsString(
            'Only a failed or undelivered message can be resent',
            (string) $response->json('errors.0.message'),
        );
    }

    /**
     * A text the way the lead send leaves it: sent without filing, then linked to the caller's message.
     *
     * @return array{0: Message, 1: Delivery}
     */
    private function sentMessage(?TransportResult $outcome): array
    {
        $this->transport->sendResult = $outcome;

        $delivery = $this->communication()
            ->send(
                $this->personWithCellphone('+18095550971'),
                'Your appointment is at 3pm',
                channels: ['sms'],
                sync: true,
                file: false
            )
            ->delivery(ChannelTypeEnum::SMS);

        $message = $this->message();
        new LinkDeliveryMessageAction($message)->execute([
            'provider' => $delivery->provider,
            'provider_message_id' => $delivery->external_id,
            'attempt_uuid' => $delivery->uuid,
        ]);

        return [$message, $delivery->fresh()];
    }

    private function message(): Message
    {
        return Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create([
                'users_id' => auth()->user()->getId(),
                'message' => ['content' => 'Your appointment is at 3pm'],
            ]);
    }
}
