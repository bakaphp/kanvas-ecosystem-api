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

            if (isset($attributes['required']) && $attributes['required']) {
                $rules[] = 'required';
            } else {
                $rules[] = 'nullable';
            }

            if (isset($attributes['type']) && $attributes['type'] === 'text') {
                $rules[] = 'string';
            }
            $validationRules[$field] = implode('|', $rules);
        }

        $validator = Validator::make(
            collect($this->request['config'])->toArray(),
            $validationRules
        );

        if ($validator->fails()) {
            // Return errors with field names as keys
            $errorMessages = $validator->errors()->all();
            throw new ValidationException($validator, response()->json(['errors' => $errorMessages]));
        }
    }
}
