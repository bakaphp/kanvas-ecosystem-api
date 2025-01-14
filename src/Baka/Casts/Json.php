<?php

declare(strict_types=1);

namespace Baka\Casts;

use Exception;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Json implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     * @psalm-suppress MixedReturnStatement
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            // First check if it's already valid JSON
            if (Str::isJson($value)) {
                return json_decode($value, true);
            }

            // Clean the string if needed (remove escaped quotes, etc)
            $cleanValue = stripslashes($value);

            // Try to decode after cleaning
            $decoded = json_decode($cleanValue, true);

            // If successful decoding after cleaning, return the decoded value
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }

            // Try to handle malformed JSON strings
            if (str_starts_with($cleanValue, '"') && str_ends_with($cleanValue, '"')) {
                $cleanValue = substr($cleanValue, 1, -1);
                $decoded = json_decode($cleanValue, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    return $decoded;
                }
            }

            // If all attempts fail, return original value
            return $value;
        } catch (Exception $e) {
            return $value;
        }
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return Str::isJson($value) || is_array($value) ? json_encode($value) : $value;
    }
}
