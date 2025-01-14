<?php

declare(strict_types=1);

namespace Baka\Casts;

use Exception;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use JsonException;

class Json implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|mixed
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        try {
            // First check if it's already valid JSON
            if (Str::isJson($value)) {
                return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }

            // Clean and normalize the value
            $cleanValue = $this->normalizeJsonString($value);

            // Try to decode after cleaning
            $decoded = json_decode($cleanValue, true, 512, JSON_THROW_ON_ERROR);

            return $decoded ?? $value;
        } catch (JsonException|Exception) {
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
        if (is_null($value)) {
            return null;
        }

        if (is_string($value) && Str::isJson($value)) {
            return $value;
        }

        if (is_array($value) || is_object($value)) {
            try {
                return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $value;
            }
        }

        return $value;
    }

    /**
     * Normalize and clean JSON string.
     */
    private function normalizeJsonString(string $value): string
    {
        $value = stripslashes($value);
        $value = str_replace(['\\"', '\"'], ['"', '"'], $value);
        $value = str_replace(['\\n', '\n'], "\n", $value);

        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            $value = substr($value, 1, -1);
        }

        return $value;
    }
}
