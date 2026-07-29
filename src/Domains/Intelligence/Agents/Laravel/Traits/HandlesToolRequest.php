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
}
