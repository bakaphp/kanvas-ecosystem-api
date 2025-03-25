<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;

class ValidateMessageSchemaAction
{
    public function __construct(
        private readonly Message $message,
        private readonly MessageType $messageType
    ) {
    }

    /**
     * Validate the message schema
     */
    public function execute(): array
    {
        $schema = json_decode($this->messageType->message_schema, true);
        $data = is_array($this->message->message) ? $this->message->message : json_decode($this->message->message, true);

        return $this->validateSchema($data, $schema);
    }

    private function validateSchema($data, $schema, $parentKey = '')
    {
        $errors = [];
        foreach ($schema['required'] as $key) {
            if (! isset($data[$key])) {
                $errors[] = "Missing required field: {$parentKey}{$key}";
            } elseif (is_array($schema['types'][$key])) {
                $errors = array_merge($errors, $this->validateSchema($data[$key], $schema['types'][$key], "{$parentKey}{$key}."));
            } elseif (gettype($data[$key]) !== $schema['types'][$key]) {
                $errors[] = "Invalid type for {$parentKey}{$key}, expected " . $schema['types'][$key];
            }
        }
        foreach ($schema['optional'] as $key) {
            if (isset($data[$key])) {
                if (is_array($schema['types'][$key])) {
                    $errors = array_merge($errors, $this->validateSchema($data[$key], $schema['types'][$key], "{$parentKey}{$key}."));
                } elseif (gettype($data[$key]) !== $schema['types'][$key]) {
                    $errors[] = "Invalid type for {$parentKey}{$key}, expected " . $schema['types'][$key];
                }
            }
        }
        return $errors;
    }
}
