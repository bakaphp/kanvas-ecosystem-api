<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Validations;

use Baka\Support\Str;
use Illuminate\Support\Facades\Validator;
use Kanvas\Social\Messages\Exceptions\MessageValidationException;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class MessageSchemaValidator
{
    #protected string|int $appId;

    public function __construct(
        private readonly Message $message,
        private readonly MessageType $messageType,
        private bool $returnValidation = false
    ) {
    }

    public function validate(): bool
    {
        if ($this->messageType->message_schema === null || $this->messageType->message_schema === '' || ! Str::isJson($this->messageType->message_schema)) {
            return true; // No schema to validate against
        }

        $schema = json_decode($this->messageType->message_schema, true);
        $data = is_array($this->message->message) ? $this->message->message : json_decode($this->message->message, true);

        return $this->validateSchema($data, $schema);
    }

    private function validateSchema(array $data, array $schema): bool
    {
        $validator = Validator::make($data, $schema);
        if ($validator->fails()) {
            if ($this->returnValidation) {
                return false;
            }

            throw new MessageValidationException(implode(', ', $validator->errors()->all()));
        }

        return true;
    }
}
