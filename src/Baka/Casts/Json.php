<?php

declare(strict_types=1);

namespace Baka\Casts;

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

        // First check if it's already valid JSON
        if (Str::isJson($value)) {
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                //if true means the json most likely is a string like this "{\"description\":\"test\"}"
                $value = substr(stripslashes($value), 1, -1);
            }

            return json_decode($value, true);
        }

        // If all attempts fail, return original value
        return $value;
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
