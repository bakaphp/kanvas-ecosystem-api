<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Concerns;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Throwable;

/**
 * Lends an agent's git token to one command on the host, then takes it back.
 *
 * Both halves of the round trip need it — the clone that fetches a private repository and the push that
 * returns the work — and they must behave identically, so this is one implementation rather than two.
 *
 * The token goes into a 0600 file over SFTP and is removed immediately afterwards, never onto the
 * command line: anything in a command line is visible in `ps` to every user on that host, and a
 * customer's server is not ours to assume is single-tenant. It is agent-scoped, and it is never placed
 * inside the directory mounted into the container — the agent works on the checkout, it does not get
 * the credential that produced it.
 */
trait UsesGitCredential
{
    private function gitToken(?Agent $agent): ?string
    {
        // Agent-scoped only. A company-wide fallback would quietly give every agent the same reach,
        // which is exactly what the per-agent allow-list exists to prevent.
        return Str::trimToNull((string) $agent?->get(AgentCustomFieldEnum::GIT_TOKEN->value));
    }

    /**
     * The `-c credential.helper=...` fragment to splice into a git command, or '' when there is no
     * token and the machine's own git access is all there is.
     */
    private function lendGitCredential(
        SshClient $client,
        ?string $token,
        string $path,
        ?string $remoteUrl
    ): string {
        if ($token === null) {
            return '';
        }

        $host = parse_url((string) $remoteUrl, PHP_URL_HOST) ?: 'github.com';

        // x-access-token is what GitHub expects for a token used as a password.
        $client->writeFile($path, 'https://x-access-token:' . $token . '@' . $host . "\n");
        $client->exec('chmod 600 ' . escapeshellarg($path), 30);

        return ' -c ' . escapeshellarg('credential.helper=store --file=' . $path);
    }

    /**
     * Best-effort, but it runs on every path: a credential left on a customer's disk is a credential
     * nobody remembers to rotate.
     */
    private function forgetGitCredential(SshClient $client, string $path): void
    {
        try {
            $client->exec('rm -f ' . escapeshellarg($path), 30);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
