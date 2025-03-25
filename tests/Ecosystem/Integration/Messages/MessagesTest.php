<?php

declare(strict_types=1);

namespace Tests\Ecosystem\Integration\Messages;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Tests\TestCase;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\Social\Messages\Actions\ValidateMessageSchemaAction;

final class MessagesTest extends TestCase
{
    public function testValidMessageSchema()
    {
        $messageType = MessageType::factory()->create([
            'message_schema' => json_encode([
                'required' => ['name', 'email'],
                'optional' => ['phone'],
                'types' => [
                    'name' => 'string',
                    'email' => 'string',
                    'phone' => 'string'
                ]
            ])
        ]);

        $this->assertIsArray(json_decode($messageType->message_schema, true));

        $messageData = [
            'name' => 'John Doe',
            'email' => "example@example.com",
            'phone' => '1234567890'
        ];

        $message = new Message([
            'message' => json_encode($messageData),
            'message_types_id' => $messageType->id,
            'apps_id' => 1,
            'users_id' => Auth::user()->getId(),
            'companies_id' => Auth::user()->currentCompanyId()
        ]);

        $message->save();

        $this->assertGreaterThan(0, $message->getId());

        $validateMessageSchema = new ValidateMessageSchemaAction($message, $messageType);
        $errors = $validateMessageSchema->execute();

        $this->assertEmpty($errors);

        
    }

    public function testInvalidMessageSchema()
    {
        $messageType = MessageType::factory()->create([
            'message_schema' => json_encode([
                'required' => ['name', 'email'],
                'optional' => ['phone'],
                'types' => [
                    'name' => 'string',
                    'email' => 'string',
                    'phone' => 'string'
                ]
            ])
        ]);

        $this->assertIsArray(json_decode($messageType->message_schema, true));

        $messageData = [
            'name' => 'John Doe',
            'email' => "example@example.com",
            'phone' => 12312313
        ];

        $message = new Message([
            'message' => json_encode($messageData),
            'message_types_id' => $messageType->id,
            'apps_id' => 1,
            'users_id' => Auth::user()->getId(),
            'companies_id' => Auth::user()->currentCompanyId()
        ]);

        $message->save();

        $this->assertGreaterThan(0, $message->getId());

        $validateMessageSchema = new ValidateMessageSchemaAction($message, $messageType);
        $errors = $validateMessageSchema->execute();

        $this->assertNotEmpty($errors);
    }
}
