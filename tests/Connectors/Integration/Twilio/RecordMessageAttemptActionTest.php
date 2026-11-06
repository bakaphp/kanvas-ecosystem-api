<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Twilio;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Twilio\Actions\RecordMessageAttemptAction;
use Kanvas\Connectors\Twilio\Models\MessageAttempt;
use Kanvas\Connectors\Twilio\Models\MessageDeliveryEvent;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Tests\TestCase;

final class RecordMessageAttemptActionTest extends TestCase
{
    public function testPersistsAcceptedAttemptAndIdempotentInitialEvent(): void
    {
        $message = $this->message();
        $response = [
            'attempt_uuid' => 'f865406c-7de9-45a8-98f1-f2224e9874c8',
            'channel' => 'sms',
            'messages' => [[
                'sid' => 'SM-attempt-1',
                'account_sid' => 'AC123',
                'messaging_service_sid' => 'MG123',
                'status' => 'queued',
                'from' => '+18095550000',
                'to' => '+18095551234',
            ]],
        ];

        new RecordMessageAttemptAction($message)->execute($response);
        new RecordMessageAttemptAction($message)->execute($response);

        $attempt = MessageAttempt::query()->where('message_sid', 'SM-attempt-1')->firstOrFail();
        $this->assertSame($message->getId(), $attempt->message_id);
        $this->assertSame('queued', $attempt->current_status);
        $this->assertSame('+18095551234', $attempt->to_number);
        $this->assertSame(1, MessageDeliveryEvent::query()->where('attempt_id', $attempt->getId())->count());
    }

    public function testPersistsSynchronousFailureWithoutMessageSid(): void
    {
        $message = $this->message();

        $attempts = new RecordMessageAttemptAction($message)->execute([
            'attempt_uuid' => '2d65467e-9ab4-4acf-b675-618344dce5bc',
            'status' => 'error',
            'messages' => [],
            'twilio_error_code' => 21610,
            'classification' => 'opted_out',
            'retryable' => false,
            'error' => 'Attempt to send to unsubscribed recipient',
        ]);

        $this->assertCount(1, $attempts);
        $this->assertNull($attempts[0]->message_sid);
        $this->assertSame(21610, $attempts[0]->last_error_code);
        $this->assertSame('opted_out', $attempts[0]->classification);
        $this->assertSame('suppress_sms', $attempts[0]->remediation_action);
        $this->assertSame(1, $attempts[0]->events()->count());
    }

    private function message(): Message
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $messageType = MessageType::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'languages_id' => 1,
                'verb' => 'twilio-sms',
            ],
            ['name' => 'Twilio SMS'],
        );

        return Message::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withMessageType($messageType)
            ->create(['users_id' => $user->getId()]);
    }
}
