<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Laravel\Traits;

use Kanvas\Users\Models\Users;
use Laravel\Ai\Tools\Request;

/**
 * Small request/context helpers shared by Laravel tool handle() methods: the acting user (Laravel
 * tools read it from auth(), unlike Neuron which injects it) and optional-string coercion.
 */
trait HandlesToolRequest
{
    protected function actingUser(): ?Users
    {
        $user = auth()->user();

        return $user instanceof Users ? $user : null;
    }

    protected function nullableString(Request $request, string $key): ?string
    {
        $value = trim((string) $request->string($key));

        return $value === '' ? null : $value;
    }

    /**
     * The partial-edit tools need "the model did not send this" to stay distinct from a sent zero,
     * which the typed accessors flatten — integer() on a missing key is 0, not null.
     */
    protected function nullableInt(Request $request, string $key): ?int
    {
        return $request->filled($key) ? $request->integer($key) : null;
    }

    protected function nullableFloat(Request $request, string $key): ?float
    {
        return $request->filled($key) ? (float) $request->float($key) : null;
    }

    protected function nullableBool(Request $request, string $key): ?bool
    {
        return $request->has($key) ? $request->boolean($key) : null;
    }

    /**
     * A free-form key→value param is declared as a STRING carrying JSON rather than an object — the
     * Neuron side does the same, for the reason spelled out in DecodesJsonObjectParam. A real array
     * is still accepted in case a provider hands back structured input.
     *
     * @return array<string, mixed>
     */
    protected function jsonObjectParam(Request $request, string $key): array
    {
        $value = $request->input($key);

        if (is_array($value)) {
            return $value;
        }

        $value = trim((string) $value);

        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}
