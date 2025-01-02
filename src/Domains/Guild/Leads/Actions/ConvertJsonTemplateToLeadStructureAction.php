<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;

class ConvertJsonTemplateToLeadStructureAction
{
    public function __construct(
        protected array $template,
        protected array $data
    ) {
    }

    /**
     * Map to lead fields.
     */
    public function execute(): array
    {
        $newMapping = $this->parseLead($this->data, $this->template);

        return $newMapping;
    }

    public function processFunctions(Lead $lead, array $newMapping): void
    {
        if (isset($newMapping['function']) && ! empty($newMapping['function'])) {
            foreach ($newMapping['function'] as $key => $value) {
                $lead->{$key}($value);
                if (method_exists($lead, $key)) {
                    $lead->{$key}($value);
                }
            }
        }
    }

    /**
     * Given the array key in dot notation find it in the array
     * https://stackoverflow.com/questions/27789932/get-value-from-array-using-string-php.
     *
     * $array = array('data' => array('one' => 'first', 'two' => 'second'));
     *  $key = 'data.one';
     *
     * get the value of one
     *
     * @return mixed
     */
    protected function findInArrayByDotNotation(string $key, array $array)
    {
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            $array = $array[$part];
        }

        return $array;
    }

    /**
     * recursive function loop through the dimensional array
     * https://stackoverflow.com/a/53736239/10697357.
     */
    public function loopArrayAndReplaceDotNotion(array $array): array
    {
        //loop each row of array
        foreach ($array as $key => $value) {
            //if the value is array, it will do the recursive
            if (is_array($value)) {
                $array[$key] = $this->loopArrayAndReplaceDotNotion($array[$key]);
            }

            if (! is_array($value)) {
                // you can do your algorithm here
                // example:
                $array[$key] = ! Str::contains((string) $value, '.') ? $value : $this->findInArrayByDotNotation($value, $this->data); // cast value to string data type
            }
        }

        return $array;
    }

    public function parseLead(array $request, array $template): array
    {
        $parsedData = [];
        $customFields = [];
        $processFields = [];
        $peopleStructure = [
            'firstname' => null,
            'lastname' => null,
            'contacts' => [],
        ];

        foreach ($template as $path => $info) {
            $value = $this->getValueFromPath($request, $path);
            $value = ! empty($value) ? $value : ($info['default'] ?? null);

            $name = $info['name'];
            $type = $info['type'];
            $pattern = $info['pattern'] ?? null; // Optional regex pattern

            match ($type) {
                'string' => $this->mapStringType($peopleStructure, $parsedData, $name, $value),
                'customField' => $this->mapCustomField($customFields, $name, $value, $pattern),
                'function' => $this->mapFunctionType($parsedData, $request, $info, $name),
                'regex' => $this->mapRegexType($parsedData, $name, $value, $pattern),
                default => null
            };

            $processFields[$name] = $value;
        }

        if (! empty($request['company']) || ! empty($request['organization'])) {
            $parsedData['organization'] = $request['company'] ?? $request['organization'];
        }

        // Add remaining unprocessed fields to custom fields
        foreach ($request as $key => $value) {
            if (! array_key_exists($key, $processFields) && ! array_key_exists($key, $customFields)) {
                $customFields[$key] = $value;
            }
        }

        $parsedData['custom_fields'] = $customFields;
        $parsedData['people'] = $peopleStructure;

        return $parsedData;
    }

    private function mapRegexType(array &$parsedData, string $name, ?string $value, ?string $pattern): void
    {
        if ($value && $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                $parsedData[$name] = $matches[1] ?? $matches[0]; // Default to the first match or full match
            } else {
                $parsedData[$name] = null; // No match found
            }
        } else {
            $parsedData[$name] = $value; // No pattern provided, use raw value
        }

    }

    private function mapCustomField(array &$customFields, string $name, ?string $value, ?string $pattern = null): void
    {
        if ($pattern) {
            // Apply regex to extract value
            if ($value && preg_match($pattern, $value, $matches)) {
                $customFields[$name] = $matches[1] ?? $matches[0]; // Use the first captured group or full match
            } else {
                $customFields[$name] = null; // No match found
            }
        } else {
            $customFields[$name] = $value; // Use raw value if no pattern
        }
    }

    private function mapStringType(array &$peopleStructure, array &$parsedData, string $name, $value): void
    {
        match ($name) {
            'firstname' => $peopleStructure['firstname'] = $value,
            'lastname' => $peopleStructure['lastname'] = $value,
            'email' => $this->addContact($peopleStructure['contacts'], ContactTypeEnum::EMAIL->value, $value),
            'phone' => $this->addContact($peopleStructure['contacts'], ContactTypeEnum::PHONE->value, $value),
            default => null
        };

        $parsedData[$name] = $value;
    }

    private function addContact(array &$contacts, int $contactTypeId, ?string $value): void
    {
        if ($value) {
            $contacts[] = [
                'contacts_types_id' => $contactTypeId,
                'value' => $value,
            ];
        }
    }

    private function mapFunctionType(array &$parsedData, array $request, array $info, string $name): void
    {
        $functionName = $info['function'];
        if (method_exists($this, $functionName) && isset($info['json'])) {
            $parsedData['function'][$functionName] = $this->{$functionName}($request, $info['json']);
        }
    }

    public function getValueFromPath(array $array, string $path): string
    {
        $keys = explode('.', $path); // Use dot notation for hierarchical keys
        $tempArray = $array;

        foreach ($keys as $key) {
            $key = trim($key); // Remove any unnecessary spaces
            if (isset($tempArray[$key])) {
                $tempArray = $tempArray[$key];
            } else {
                return ''; // Return an empty string if the key does not exist
            }
        }

        // Ensure the value is a string or numeric
        return is_string($tempArray) || is_numeric($tempArray) ? (string) $tempArray : '';
    }

    /**
     * @deprecated we need to refactor
     */
    public function setPeople(array $request, array $json): array
    {
        $person = [];
        $person['name'] = $this->getValueFromPath($request, $json['name']);
        if (! empty($json['organization'])) {
            $person['organization'] = $this->getValueFromPath($request, $json['organization']);
        }
        $person['contacts'] = [];
        foreach ($json['contacts'] as $contact) {
            $person['contacts'][] = [
                'contacts_types_id' => $contact['contacts_types_id'],
                'value' => $this->getValueFromPath($request, $contact['value']),
            ];
        }

        // Address support
        $person['address'] = [];
        if (! isset($json['address'])) {
            return $person;
        }

        foreach ($json['address'] as $addressInfo) {
            $address = [];
            $address['address'] = $this->getValueFromPath($request, $addressInfo['address']);
            $address['address_2'] = $this->getValueFromPath($request, $addressInfo['address_2']);
            $address['city'] = $this->getValueFromPath($request, $addressInfo['city']);
            $address['state'] = $this->getValueFromPath($request, $addressInfo['state']);
            $address['zip'] = $this->getValueFromPath($request, $addressInfo['zip']);
            $person['address'][] = $address;
        }

        return $person;
    }
}
