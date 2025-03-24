<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\Messages\Models\UserMessage;
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
        $data = json_decode($this->message->message, true);

        return $this->validateJson($data, $schema);
    }

    private function validateJson($data, $schema, $parentKey = '')
    {
        $errors = [];
        foreach ($schema['required'] as $key) {
            if (! isset($data[$key])) {
                $errors[] = "Missing required field: {$parentKey}{$key}";
            } elseif (is_array($schema['types'][$key])) {
                $errors = array_merge($errors, $this->validateJson($data[$key], $schema['types'][$key], "{$parentKey}{$key}."));
            } elseif (gettype($data[$key]) !== $schema['types'][$key]) {
                $errors[] = "Invalid type for {$parentKey}{$key}, expected " . $schema['types'][$key];
            }
        }
        foreach ($schema['optional'] as $key) {
            if (isset($data[$key])) {
                if (is_array($schema['types'][$key])) {
                    $errors = array_merge($errors, $this->validateJson($data[$key], $schema['types'][$key], "{$parentKey}{$key}."));
                } elseif (gettype($data[$key]) !== $schema['types'][$key]) {
                    $errors[] = "Invalid type for {$parentKey}{$key}, expected " . $schema['types'][$key];
                }
            }
        }
        return $errors;
    }
}
