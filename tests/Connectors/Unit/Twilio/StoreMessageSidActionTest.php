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

        $sid = new StoreMessageSidAction($message)->execute([
            'channel' => 'sms',
            'messages' => [
                ['sid' => 'SM123'],
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
}
