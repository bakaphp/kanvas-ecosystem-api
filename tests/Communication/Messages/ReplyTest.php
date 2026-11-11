<?php

declare(strict_types=1);

namespace Tests\Communication\Messages;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Communication\Messages\Actions\FileDeliveryMessageAction;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\Models\Message;
use Tests\Communication\Concerns\BuildsCommunicationFixtures;
use Tests\TestCase;

final class ReplyTest extends TestCase
{
    use BuildsCommunicationFixtures;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'workflow', 'crm', 'social'];

    public function testAReplyLeavesThroughTheConversationsConnectionNotTheDefault(): void
    {
        $transport = $this->useFakeProvider();
        $original = $this->defaultSmsConnection(number: '+18095550100');
        $person = $this->personWithCellphone('+18095550171');

        $sent = $this->communication()->send($person, 'Hello', channels: ['sms'])->delivery(ChannelTypeEnum::SMS);

        // The company's default moves to another number after the conversation started.
        $this->defaultSmsConnection(number: '+18095550222');

        $reply = $this->communication()->reply(Message::query()->findOrFail($sent->message_id), 'Thanks!');
        $delivery = $reply->delivery(ChannelTypeEnum::SMS);

        $this->assertSame(OperationKindEnum::REPLY, $reply->request->kind);
        $this->assertSame($sent->message_id, $reply->request->reply_to_message_id);
        $this->assertSame($original->getId(), $delivery->connection_id);
        $this->assertSame('+18095550171', $delivery->to_address);
        $this->assertSame(DeliveryStatusEnum::ACCEPTED, $delivery->status);
        $this->assertCount(2, $transport->sent);
    }

    /**
     * An agent's answer is filed — and possibly held for approval — before it is sent. Sending it
     * records the delivery on that message instead of filing a second copy of the same words.
     */
    public function testAReplyTheCallerAlreadyFiledIsRecordedOnThatMessage(): void
    {
        $this->useFakeProvider();
        $this->defaultSmsConnection(number: '+18095550100');
        $sent = $this->communication()
            ->send(
                $this->personWithCellphone('+18095550172'),
                'Hello',
                channels: ['sms'],
                sync: true
            )
            ->delivery(ChannelTypeEnum::SMS);
        $conversation = Message::query()->findOrFail($sent->message_id);

        $filed = Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['users_id' => auth()->user()->getId(), 'message' => ['content' => 'Thanks!', 'from_me' => true]]);
        $messagesBefore = Message::query()->count();

        $delivery = $this->communication()
            ->reply(
                $conversation,
                'Thanks!',
                sync: true,
                filedAs: $filed
            )
            ->delivery(ChannelTypeEnum::SMS);

        $this->assertSame($filed->getId(), $delivery->message_id);
        $this->assertSame($delivery->getId(), $filed->fresh()->message['communication']['delivery_id']);
        $this->assertSame($messagesBefore, Message::query()->count());
    }

    public function testAMessageOutsideAnyConversationCannotBeRepliedTo(): void
    {
        $this->useFakeProvider();

        $note = Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->create(['users_id' => auth()->user()->getId()]);

        $this->expectException(ValidationException::class);

        $this->communication()->reply($note, 'Thanks!');
    }

    public function testADeliveryCannotBeFiledOnAnotherCompanysMessage(): void
    {
        $this->useFakeProvider();
        $this->defaultSmsConnection();
        $delivery = $this->communication()
            ->send($this->personWithCellphone(), 'Hello', channels: ['sms'])
            ->delivery(ChannelTypeEnum::SMS);

        $foreignMessage = Message::factory()
            ->withAppId($this->currentApp()->getId())
            ->withCompanyId($this->otherCompany()->getId())
            ->create(['users_id' => auth()->user()->getId(), 'message' => ['content' => 'Private conversation']]);

        $delivery->request->update(['filed_message_id' => $foreignMessage->getId()]);

        $this->expectException(ModelNotFoundException::class);

        new FileDeliveryMessageAction($delivery, Content::text('Hello'))->execute();
    }
}
