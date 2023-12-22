<?php

declare(strict_types=1);

namespace Baka\Validations;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordValidation
{
    public static function validateArray(array $data): array
    {
        $rules = [
            'password' => ['required', self::passwordRules($data['account_type'] ?? null)],
        ];

        $validator = Validator::make($data, $rules);

        return $validator->validate();
    }

    /**
     * Define the password validation rules based on account type.
     */
    protected static function passwordRules(?string $accountType = null): PasswordRule
    {
        $baseRule = PasswordRule::min(8)->uncompromised();

        /* switch ($accountType) {
            case 'standard':
                return $baseRule->min(9)->mixedCase()->numbers()->symbols();
            case 'admin':
                return $baseRule->min(12)->mixedCase()->numbers()->symbols();
            case 'service':
                return $baseRule->min(15)->mixedCase()->numbers()->symbols();
            case 'robot':
                return $baseRule->min(15)->mixedCase()->numbers()->symbols();
            default:
                return $baseRule->min(15)->mixedCase()->numbers()->symbols();
        } */

        return $baseRule;
    }
}
