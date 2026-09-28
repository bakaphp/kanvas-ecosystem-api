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

        // A body written as prose or markdown carries no named fields, so it cannot satisfy a field
        // schema. Reject it as a validation failure rather than fataling on a null $data.
        if (! is_array($data)) {
            if ($this->returnValidation) {
                return false;
            }

            throw new MessageValidationException(
                'The message body must be a JSON object to satisfy the schema declared by this message type.'
            );
        }

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
