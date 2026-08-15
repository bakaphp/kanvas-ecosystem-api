<?php

declare(strict_types=1);

namespace Tests\Connectors\Unit\Twilio;

use Kanvas\Connectors\Twilio\Actions\StoreMessageSidAction;
use Kanvas\Connectors\Twilio\Enums\CustomFieldEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Social\Messages\Models\Message;
use Mockery;
use Tests\TestCaseUnit;

final class StoreMessageSidActionTest extends TestCaseUnit
{
    public function testStoresTwilioSidAsMessageCustomField(): void
    {
        $message = Mockery::mock(Message::class);
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::MESSAGE_SID->value, 'SM123')
            ->andReturn(Mockery::mock(AppsCustomFields::class));
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::CURRENT_STATUS->value, 'queued')
            ->andReturn(Mockery::mock(AppsCustomFields::class));
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::LAST_STATUS_AT->value, Mockery::type('string'))
            ->andReturn(Mockery::mock(AppsCustomFields::class));

        $sid = new StoreMessageSidAction($message)->execute([
            'channel' => 'sms',
            'messages' => [
                ['sid' => 'SM123', 'status' => 'queued'],
            ],
        ]);

        $this->assertSame('SM123', $sid);
    }

    public function testDoesNotStoreCustomFieldWithoutTwilioSid(): void
    {
        $message = Mockery::mock(Message::class);
        $message->shouldNotReceive('set');

        $sid = new StoreMessageSidAction($message)->execute([
            'channel' => 'email',
            'messages' => [],
        ]);

        $this->assertNull($sid);
    }

    public function testStoresSynchronousTwilioFailureWithoutSid(): void
    {
        $message = Mockery::mock(Message::class);
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::LAST_ERROR_CODE->value, '21610');
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::CURRENT_STATUS->value, 'failed');
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::LAST_STATUS_AT->value, Mockery::type('string'));
        $message->shouldReceive('set')
            ->once()
            ->with(CustomFieldEnum::LAST_ERROR_MESSAGE->value, 'Attempt to send to unsubscribed recipient');

        $sid = new StoreMessageSidAction($message)->execute([
            'status' => 'error',
            'twilio_error_code' => 21610,
            'error' => 'Attempt to send to unsubscribed recipient',
            'messages' => [],
        ]);

        $this->assertNull($sid);
    }
}
