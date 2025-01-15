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
        return Str::isJson($value) ? json_decode($value, true) : $value;
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
