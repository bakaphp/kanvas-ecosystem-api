<?php

namespace Kanvas\SystemModules\Services;

use Illuminate\Validation\ValidationException;
use Kanvas\SystemModules\Models\SystemModules;

class SystemModulesServices
{
    public static function validateFields(SystemModules $systemModule, array $mapperFields)
    {
        $requiredFields = [];

        foreach ($systemModule->fields as $field => $attributes) {
            if (isset($attributes['required']) && $attributes['required']) {
                $requiredFields[] = $field;
            }
        }
    
        $missing = array_diff($requiredFields, $mapperFields);
    
        if (!empty($missing)) {
            throw ValidationException::withMessages([
                'fields' => ['Missing required fields: ' . implode(', ', $missing)],
            ]);
        }
    }
}
