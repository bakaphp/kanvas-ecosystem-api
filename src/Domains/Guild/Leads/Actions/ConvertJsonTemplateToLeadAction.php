<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Leads\Models\Lead;

class ConvertJsonTemplateToLeadAction
{
    public function __construct(
        protected Lead $lead,
        protected array $template,
        protected array $data
    ) {
    }

    /**
     * Map to lead fields.
     */
    public function execute(): Lead
    {
        $newMapping = $this->parseLead($this->data, $this->template);

        if (method_exists($this->lead, 'setCustomFields')) {
            $this->lead->setCustomFields($newMapping['customField']);
        }

        if (method_exists($this->lead, 'disableWorkflows')) {
            $this->lead->disableWorkflows();
        }

        $this->lead->saveOrFail($newMapping['modelInfo']);

        if (isset($newMapping['function']) && ! empty($newMapping['function'])) {
            foreach ($newMapping['function'] as $key => $value) {
                $this->lead->{$key}($value);
                if (method_exists($this->lead, $key)) {
                    $this->lead->{$key}($value);
                }
            }
        }

        return $this->lead;
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

        // Iterate through the template
        foreach ($template as $path => $info) {
            $value = $this->getValueFromPath($request, $path);

            if ($info['type'] === 'string') {
                $parsedData['modelInfo'][$info['name']] = $value;
            } elseif ($info['type'] === 'customField') {
                $parsedData['customField'][$info['name']] = $value;
            } elseif ($info['type'] === 'function') {
                $functionName = $info['function'];
                if (method_exists($this, $functionName) && isset($info['json'])) {
                    $parsedData['function'][$functionName] = $this->{$functionName}($request, $info['json']);
                }
            }
        }

        return $parsedData;
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
