<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\SshClient;

/**
 * Makes a directory on the machine exist and belong to whoever we SSH in as.
 *
 * Two places need it and they are not adjacent: the container needs its worktree root before it starts,
 * and git needs the mirror root before it clones. A fresh box owns `/srv/kanvas` as root, so whichever
 * of them runs first fails — and the clone failing reads like a container problem, which it is not. git
 * runs on the HOST; the mirror is never inside the container at all.
 *
 * `whoami`, not the machine's `ssh_user` column: that column is who we asked to connect as, this is who
 * the commands actually run as, and a chown against the wrong name fixes nothing while looking like it
 * tried.
 *
 * Probe before chowning. The worktree root holds every session checkout plus opencode's session store —
 * gigabytes within days — and a recursive chown over all of it on every cold start buys nothing when
 * the ownership is already right.
 */
trait PreparesHostDirectory
{
    private function prepareHostDirectory(
        SshClient $client,
        string $path,
        ?string $machineName = null
    ): void {
        $quoted = escapeshellarg($path);

        $client->exec('mkdir -p ' . $quoted . ' 2>/dev/null || sudo -n mkdir -p ' . $quoted . ' 2>&1 || true', 60);

        if ($this->hostDirectoryIsWritable($client, $quoted)) {
            return;
        }

        $owner = trim($client->exec('whoami', 30));
        $client->exec('sudo -n chown -R ' . escapeshellarg($owner) . ' ' . $quoted . ' 2>&1 || true', 120);

        if ($this->hostDirectoryIsWritable($client, $quoted)) {
            return;
        }

        $who = $owner === '' ? 'the SSH user' : $owner;

        throw new ValidationException(
            'The coding workspace on ' . ($machineName ?? 'this machine') . ' is not usable: ' . $path
            . ' must exist and be writable by ' . $who . '. Run on that host: sudo mkdir -p ' . $path
            . ' && sudo chown -R ' . ($owner === '' ? '<ssh_user>' : $owner) . ' ' . $path
        );
    }

    private function hostDirectoryIsWritable(SshClient $client, string $quotedPath): bool
    {
        return trim($client->exec('test -w ' . $quotedPath . ' && echo WRITABLE || echo NO', 30)) === 'WRITABLE';
    }
}
