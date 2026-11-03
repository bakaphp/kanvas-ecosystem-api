<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Kanvas\Connectors\OpenCode\Concerns\RunsCheckedSshCommands;
use Kanvas\Connectors\OpenCode\Concerns\UsesGitCredential;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * One bare mirror per (company, repo) on the host, cloned once and fetched after. It is the expensive
 * part of a launch — a large repository is minutes on first clone — and it is shared by every session
 * the company runs against the repo, which is why it can be warmed ahead of the first job.
 *
 * Authenticated, because a private repository is the normal case. Without the token git gets an
 * anonymous request and GitHub answers "Repository not found" for a private repo rather than
 * "forbidden" — so the failure reads as a typo and sends everyone looking in the wrong place.
 */
class EnsureRepositoryMirrorAction
{
    use RunsCheckedSshCommands;
    use UsesGitCredential;

    /** @param string $credentialKey what makes the lent credential's temp file unique: a session uuid, a job id */
    public function __construct(
        private readonly SshClient $client,
        private readonly ?Agent $agent,
        private readonly string $mirror,
        private readonly string $cloneUrl,
        private readonly string $credentialKey,
    ) {
    }

    /**
     * Deliberately outside the worktree root, which is mounted into the container: the mirror holds
     * every branch this company has ever run against the repository, and an agent working on one task
     * has no business reading — or writing — the rest of them.
     */
    public static function pathFor(string $root, int $companyId, string $slug): string
    {
        return rtrim($root, '/') . '/repos/' . $companyId . '/' . $slug . '.git';
    }

    public function execute(): void
    {
        $credentialPath = '/tmp/kanvas-git-clone-' . $this->credentialKey;
        $credential = $this->lendGitCredential(
            $this->client,
            $this->gitToken($this->agent),
            $credentialPath,
            $this->cloneUrl
        );

        try {
            $exists = $this->client->exec(
                'test -d ' . escapeshellarg($this->mirror) . ' && echo EXISTS || echo MISSING',
                30
            );

            if (str_contains($exists, 'EXISTS')) {
                $this->runChecked(
                    $this->client,
                    'git' . $credential . ' --git-dir=' . escapeshellarg($this->mirror) . ' fetch --prune origin',
                    'fetch the repository mirror',
                    600
                );

                return;
            }

            $this->runChecked($this->client, 'mkdir -p ' . escapeshellarg(dirname($this->mirror)), 'create the mirror directory');
            $this->runChecked(
                $this->client,
                'git' . $credential . ' clone --mirror '
                . escapeshellarg($this->cloneUrl) . ' ' . escapeshellarg($this->mirror),
                'clone the repository',
                900
            );
        } finally {
            $this->forgetGitCredential($this->client, $credentialPath);
        }
    }
}
