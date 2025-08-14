<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use InvalidArgumentException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Social\Messages\DataTransferObject\MessageInput;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;

class CreateMessageFromTypeAction
{
    public function __construct(
        protected UserInterface $user,
        protected CompanyInterface $company,
        protected AppInterface $app
    ) {
    }

    public function execute(
        MessageType $messageType,
        mixed $data,
        ?SystemModules $systemModule = null,
        mixed $entityId = null
    ): Message {
        if (Str::isJson($data)) {
            $data = json_decode($data, true);
        }

        $template = json_decode($messageType->template, true);

        if (! $template) {
            throw new InvalidArgumentException('Invalid JSON template');
        }

        $mappedMessage['message'] = $this->mapDataToTemplate($template, $data);
        $messageDto = MessageInput::fromArray(
            $mappedMessage,
            $this->user,
            $messageType,
            $this->company,
            $this->app
        );

        $action = new CreateMessageAction(
            $messageDto,
            $systemModule,
            $entityId
        );
        $message = $action->execute();
        return $message;
    }

    private function mapDataToTemplate(array $template, array $data): array
    {
        $result = [];
        $errors = [];

        $normalFields = [];
        $nestedFields = [];

        foreach ($template as $field => $config) {
            if (strpos($field, '.') === false || $config['type'] === 'array') {
                $normalFields[$field] = $config;
            } else {
                $nestedFields[$field] = $config;
            }
        }

        foreach ($normalFields as $field => $config) {
            $type = $config['type'] ?? 'string';
            $required = $config['required'] ?? false;

            $value = $this->getNestedValue($data, $field);

            if ($required && ($value === null || $value === '')) {
                $errors[] = "Field '$field' is required";
                continue;
            }

            if (! $required && ($value === null || $value === '')) {
                continue;
            }

            $convertedValue = $this->convertType($value, $type, $field);

            if ($convertedValue !== null) {
                $this->setNestedValue($result, $field, $convertedValue);
            }
        }

        $this->processNestedArrayFields($nestedFields, $data, $result, $errors);

        if (! empty($errors)) {
            throw new ValidationException('Template validation failed: ' . implode(', ', $errors));
        }
        return $result;
    }

    private function processNestedArrayFields(array $arrayFields, array $data, array &$result, array &$errors): void
    {
        $groupedFields = [];

        foreach ($arrayFields as $field => $config) {
            $parts = explode('.', $field);
            $arrayName = $parts[0];
            $fieldName = $parts[1];
            $groupedFields[$arrayName][$fieldName] = $config;
        }

        foreach ($groupedFields as $arrayName => $fields) {
            if (! isset($data[$arrayName]) || ! is_array($data[$arrayName])) {
                foreach ($fields as $fieldName => $config) {
                    if ($config['required']) {
                        $errors[] = "Field '{$arrayName}.{$fieldName}' is required";
                    }
                }
                continue;
            }

            $arrayData = $data[$arrayName];
            $processedArray = [];

            foreach ($arrayData as $index => $item) {
                if (! is_array($item)) {
                    $processedArray[$index] = $item;
                    continue;
                }

                $processedItem = [];

                foreach ($fields as $fieldName => $config) {
                    $type = $config['type'] ?? 'string';
                    $required = $config['required'] ?? false;

                    $value = $item[$fieldName] ?? null;

                    if ($required && ($value === null || $value === '')) {
                        $errors[] = "Field '{$arrayName}[{$index}].{$fieldName}' is required";
                        continue;
                    }

                    if (! $required && ($value === null || $value === '')) {
                        continue;
                    }
                    $convertedValue = $this->convertType($value, $type, "{$arrayName}.{$fieldName}");

                    if ($convertedValue !== null) {
                        $processedItem[$fieldName] = $convertedValue;
                    }
                }
                $processedArray[$index] = $processedItem;
            }

            if (! empty($processedArray) || isset($result[$arrayName])) {
                $result[$arrayName] = $processedArray;
            }
        }
    }

    private function getNestedValue(array $data, string $key)
    {
        $keys = explode('.', $key);
        $value = $data;

        foreach ($keys as $k) {
            if (is_array($value) && array_key_exists($k, $value)) {
                $value = $value[$k];
            } else {
                return null;
            }
        }

        return $value;
    }

    private function setNestedValue(array &$result, string $key, $value): void
    {
        $keys = explode('.', $key);
        $current = &$result;
        $lastKey = array_pop($keys);

        foreach ($keys as $k) {
            if (! isset($current[$k])) {
                $current[$k] = [];
            }
            $current = &$current[$k];
        }

        $current[$lastKey] = $value;
    }

    private function convertType($value, string $type, string $field)
    {
        switch ($type) {
            case 'integer':
                if (! is_numeric($value)) {
                    throw new ValidationException("Field '$field' must be an integer");
                }
                return (int) $value;

            case 'number':
                if (! is_numeric($value)) {
                    throw new ValidationException("Field '$field' must be a number");
                }
                return (float) $value;

            case 'boolean':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

            case 'string':
                return (string) $value;

            case 'array':
                if (! is_array($value)) {
                    throw new ValidationException("Field '$field' must be an array");
                }
                return $value;

            default:
                return $value;
        }
    }
}
