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

        // Initialize people structure with placeholders
        $peopleStructure = [
            'firstname' => null,
            'lastname' => null,
            'contacts' => [],
        ];

        // Iterate through the template and map values accordingly
        foreach ($template as $path => $info) {
            $value = $this->getValueFromPath($request, $path);
            $name = $info['name'];
            $type = $info['type'];

            match ($type) {
                'string' => $this->mapStringType($peopleStructure, $parsedData, $name, $value),
                'customField' => $customFields[$name] = $value,
                'function' => $this->mapFunctionType($parsedData, $request, $info, $name),
                default => null
            };
        }

        $parsedData['modelInfo']['custom_fields'] = $customFields;
        $parsedData['modelInfo']['people'] = $peopleStructure;

        return $parsedData;
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

        $parsedData['modelInfo'][$name] = $value;
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
        $values = [];
        $paths = explode(' ', $path);
        foreach ($paths as $p) {
            $keys = explode('.', $p);
            $tempArray = $array;
            foreach ($keys as $key) {
                if (isset($tempArray[$key])) {
                    $tempArray = $tempArray[$key];
                } else {
                    $tempArray = null;

                    break;
                }
            }
            // Check if the value is a string before appending
            if (is_string($tempArray) || is_numeric($tempArray)) {
                $values[] = $tempArray;
            }
        }

        return implode(' ', $values);
    }

    /**
     * @deprecated we need to refactor
     */
    public function setPeople(array $request, array $json): array
    {
        $person = [];
        $person['name'] = $this->getValueFromPath($request, $json['name']);
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
