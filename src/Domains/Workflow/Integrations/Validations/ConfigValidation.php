<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Validations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfigValidation
{
    public function __construct(
        protected array $config,
        protected array $request
    ) {
    }

    public function validate(): void
    {
        $validationRules = [];

        foreach ($this->config as $field => $attributes) {
            $rules = [];
    
            // Check for required
            if (isset($attributes['required']) && $attributes['required']) {
                $rules[] = 'required';
            }
    
            // Check for type
            if (isset($attributes['type'])) {
                if ($attributes['type'] === 'text') {
                    $rules[] = 'string';
                }
            }
            $validationRules[$field] = implode('|', $rules);
        }

        $validator = Validator::make(
            json_decode(json_encode($this->request['config']), true),
            $validationRules
        );

        if ($validator->fails()) {
            // Return errors with field names as keys
            $errorMessages = $validator->errors()->all();
            throw new ValidationException($validator, response()->json(['errors' => $errorMessages]));
        }
    }
}
