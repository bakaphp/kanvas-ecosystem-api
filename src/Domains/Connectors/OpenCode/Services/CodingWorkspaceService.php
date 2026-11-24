<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\ConfigurationEnum;

/**
 * Where mirrors, worktrees, the opencode store and (on a host) the pinned binaries live on a machine.
 * One reader of "the setting or /srv/kanvas".
 */
final class CodingWorkspaceService
{
    public const string DEFAULT_ROOT = '/srv/kanvas';

    public static function rootFor(AppInterface $app): string
    {
        return rtrim(Str::trimToNull((string) $app->get(ConfigurationEnum::WORKSPACE_ROOT->value)) ?? self::DEFAULT_ROOT, '/');
    }
}
