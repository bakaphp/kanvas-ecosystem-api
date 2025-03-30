<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Validations;

use Illuminate\Support\Facades\Validator;
use Kanvas\Social\Messages\Exceptions\MessageValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessageSchemaValidator
{
    #protected string|int $appId;

    public function __construct(
        private readonly Message $message,
        private readonly MessageType $messageType
    ) {
    }

    public function validate(): void
    {
        $schema = json_decode($this->messageType->message_schema, true);
        $data = is_array($this->message->message) ? $this->message->message : json_decode($this->message->message, true);
        $this->validateSchema($data, $schema);
    }

    private function validateSchema(array $data, array $schema): void
    {
        $validator = Validator::make($data, $schema);
        if ($validator->fails()) {
            throw new MessageValidationException(implode(', ', $validator->errors()->all()));
        }
    }
}
