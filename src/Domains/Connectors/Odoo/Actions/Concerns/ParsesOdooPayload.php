<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Odoo\Actions\Concerns;

/**
 * Shared by `PullPeopleAction` and `PullLeadAction` — both split a single Odoo `name`/`contact_name`
 * field into first/last, and both read a many2one relation field.
 */
trait ParsesOdooPayload
{
    /**
     * @return array{0: string, 1: string}
     */
    private function splitName(string $fullName): array
    {
        $parts = explode(' ', trim($fullName), 2);

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /**
     * Odoo's JSON-RPC serializes an unset many2one field as `false` and a set one as
     * `[id, "display_name"]` — never a bare id.
     */
    private function relationId(mixed $value): ?string
    {
        return is_array($value) && isset($value[0]) ? (string) $value[0] : null;
    }

    private function relationName(mixed $value): ?string
    {
        return is_array($value) && isset($value[1]) ? (string) $value[1] : null;
    }
}
