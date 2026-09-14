<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Support;

use Override;

/**
 * The verifier's boundary: it may read, and nothing else.
 *
 * Independence is the whole value. A verifier that can fix what it finds stops being a check and
 * becomes another worker — and one with an obvious incentive to make the problem go away rather than
 * report it. So the rule is inverted from {@see WorkerToolPolicy}: instead of naming what is denied,
 * this names the verbs that are allowed, and everything else is stripped.
 *
 * An allow-list rather than a deny-list because the failure modes differ. A worker missing a denied
 * tool is inconvenienced; a verifier accidentally holding a write tool silently invalidates every
 * verification it has ever produced, and nobody finds out.
 */
class VerifierToolPolicy extends ScopedToolPolicy
{
    private static bool $active = false;

    /**
     * Verbs a read-only tool starts with. Matched as a prefix on the snake_case tool id, so a new
     * `find_*` or `query_*` tool is covered the day it is written without anyone remembering to
     * update this list — while a `create_*` or `send_*` never is.
     *
     * @var list<string>
     */
    private const array READ_PREFIXES = [
        'read_',
        'get_',
        'list_',
        'find_',
        'search_',
        'query_',
        'check_',
        'describe_',
        'capability_lookup',
        'who_is_',
        'current_time',
    ];

    #[Override]
    public static function permits(string $toolName): bool
    {
        foreach (self::READ_PREFIXES as $prefix) {
            if (str_starts_with($toolName, $prefix)) {
                return true;
            }
        }

        return false;
    }

    #[Override]
    public static function isActive(): bool
    {
        return self::$active;
    }

    #[Override]
    protected static function setActive(bool $active): void
    {
        self::$active = $active;
    }
}
