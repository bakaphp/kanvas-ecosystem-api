<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\RunsCheckedSshCommands;
use Kanvas\Connectors\OpenCode\Concerns\UsesGitCredential;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Connectors\OpenCode\SshClient;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * Commits a finished session's work and pushes its branch — from the HOST, never the container.
 *
 * The container holds no git credential, which is what makes "the agent cannot push" a fact about the
 * environment rather than a rule it is asked to follow. The push runs here, in a process Kanvas
 * controls, and the agent cannot invoke it.
 *
 * The credential comes from Kanvas when it holds one, and otherwise from whatever git access the
 * machine already has. Supplying it per push is the better arrangement for a customer-hosted runtime:
 * nobody has to install a key on their server, rotating it here takes effect on the next push, and the
 * token exists on their disk only for the seconds the push runs.
 *
 * Refuses on protected paths regardless of who approved: an approval is a human agreeing to the change
 * they were shown, and a diff that reaches CI config or secrets is not that change.
 */
class PushSessionBranchAction
{
    use RunsCheckedSshCommands;
    use UsesGitCredential;

    /**
     * Branches that are never a valid push target, whatever the repository's own settings say. The
     * tenant configures `branch_prefix`, so the branch name is not entirely ours — and a push to a
     * trunk is the one mistake here that cannot be undone by deleting a branch.
     */
    private const array NEVER_PUSH_TO = [
        'main',
        'master',
        'develop',
        'development',
        'staging',
        'production',
        'release',
        'trunk',
        'head',
    ];

    /**
     * Files Kanvas writes into the workspace for its own use. They are already in the checkout's
     * `info/exclude`, but that is a setting; this is the guarantee. A bug in the exclusion must not be
     * able to commit our runtime config into somebody's repository.
     *
     * @var list<string>
     */
    private const array KANVAS_ARTIFACTS = ['opencode.json', '.kanvas'];

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly string $commitMessage,
        /** @var list<string> */
        private readonly array $protectedPaths = [],
        private readonly ?string $remoteUrl = null,
        private readonly string $baseBranch = 'main',
    ) {
    }

    /**
     * @return array{branch: string, pushed: bool, files: int}
     */
    public function execute(): array
    {
        $workspace = Str::trimToNull($this->session->workspace_path);
        $branch = Str::trimToNull($this->session->branch);

        if ($workspace === null || $branch === null) {
            throw new ValidationException(
                'This session has no worktree of its own, so there is nothing to push. Attach mode shares '
                . 'one workspace and creates no branch.'
            );
        }

        $this->assertPushableBranch($branch);

        $machine = $this->session->machine;

        if ($machine === null) {
            throw new ValidationException('This session has no machine, so there is nowhere to push from.');
        }

        $client = SshClient::fromMachine($machine);

        try {
            $changed = $this->changedFiles($client, $workspace);

            if ($changed === []) {
                return ['branch' => $branch, 'pushed' => false, 'files' => 0];
            }

            $this->assertNoProtectedPaths($changed);
            $this->commit($client, $workspace);
            $this->push($client, $workspace, $branch);
        } finally {
            $this->forgetGitCredential($client, $this->credentialPath());
            $client->disconnect();
        }

        $this->session->saveOrFail();

        return ['branch' => $branch, 'pushed' => true, 'files' => count($changed)];
    }

    /**
     * By default an agent's work only ever lands on a branch of its own; `CODING_ALLOW_TRUNK_PUSH` on
     * the agent lifts that for the cases where pushing to a trunk is genuinely the job.
     *
     * Checked here rather than only where the branch is named, because this is the boundary: the name
     * comes from a tenant-configurable prefix and the session row is a database value. Deleting an
     * unwanted branch is easy; an unwanted trunk push is not.
     */
    private function assertPushableBranch(string $branch): void
    {
        // Anything git would accept but a human would not recognise as a branch — a ref path, an
        // option-looking name — has no business here, whatever the agent is allowed to push to.
        if (preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $branch) !== 1 || str_contains($branch, '..')) {
            throw new ValidationException('Refusing to push: "' . $branch . '" is not a valid branch name.');
        }

        if ($this->trunkPushAllowed()) {
            return;
        }

        $normalised = mb_strtolower(trim($branch, "/ \t\n\r\0\x0B"));

        if ($normalised === mb_strtolower($this->baseBranch) || in_array($normalised, self::NEVER_PUSH_TO, true)) {
            throw new ValidationException(
                'Refusing to push: "' . $branch . '" is a base or protected branch, and this agent is not '
                . 'allowed to push to one. Set ' . AgentCustomFieldEnum::ALLOW_TRUNK_PUSH->value
                . ' on the agent if that is intended.'
            );
        }
    }

    private function trunkPushAllowed(): bool
    {
        return filter_var(
            (string) $this->session->agent?->get(AgentCustomFieldEnum::ALLOW_TRUNK_PUSH->value),
            FILTER_VALIDATE_BOOLEAN
        );
    }

    /**
     * @return list<string>
     */
    private function changedFiles(SshClient $client, string $workspace): array
    {
        $output = $client->exec('git -C ' . escapeshellarg($workspace) . ' status --porcelain', 60);
        $files = [];

        foreach (preg_split('/\r?\n/', trim($output)) ?: [] as $line) {
            $path = trim(mb_substr(trim($line), 2));

            if ($path !== '' && ! $this->isKanvasArtifact($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    private function isKanvasArtifact(string $path): bool
    {
        foreach (self::KANVAS_ARTIFACTS as $artifact) {
            if ($path === $artifact || str_starts_with($path, $artifact . '/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $changed
     */
    private function assertNoProtectedPaths(array $changed): void
    {
        $hits = [];

        foreach ($changed as $path) {
            foreach ($this->protectedPaths as $protected) {
                if (fnmatch(rtrim($protected, '/') . '*', $path)) {
                    $hits[] = $path;

                    break;
                }
            }
        }

        if ($hits !== []) {
            throw new ValidationException(
                'Refusing to push: the diff touches protected paths (' . implode(', ', $hits) . ').'
            );
        }
    }

    private function commit(SshClient $client, string $workspace): void
    {
        // Authored by the agent, so `git log` attributes the work honestly rather than to whoever's
        // identity happens to be configured on the machine.
        $author = escapeshellarg(($this->session->agent?->name ?? 'Kanvas agent') . ' <agent@kanvas.dev>');

        $this->runChecked(
            $client,
            // Plain `add -A`, then unstage ours — NOT `:(exclude)` pathspecs. A negative pathspec still
            // *matches* the path it excludes, so git counts the artifacts as explicitly named, sees they
            // are ignored, and refuses the entire `add`. The guard against committing them stopped every
            // commit instead.
            //
            // `rm --cached` rather than `reset`, because a first commit into an empty repository has no
            // HEAD for `reset` to resolve.
            'git -C ' . escapeshellarg($workspace) . ' add -A'
            . ' && git -C ' . escapeshellarg($workspace) . ' rm -r --cached -q --ignore-unmatch -- '
            . implode(' ', array_map('escapeshellarg', self::KANVAS_ARTIFACTS)),
            'stage the changes'
        );
        $this->runChecked(
            $client,
            'git -C ' . escapeshellarg($workspace)
            . ' -c user.name=' . escapeshellarg($this->session->agent?->name ?? 'Kanvas agent')
            . ' -c user.email=agent@kanvas.dev'
            . ' commit --author=' . $author
            . ' -m ' . escapeshellarg($this->commitMessage),
            'commit the changes'
        );
    }

    /**
     * Pushes with a credential Kanvas supplies, when it holds one — otherwise with whatever git access
     * the machine already has.
     *
     * The token is written to a 0600 file over SFTP and removed immediately afterwards, rather than
     * passed on the command line: anything in the command line is visible in `ps` to every user on that
     * host, and a customer's server is not ours to assume is single-tenant.
     */
    private function push(SshClient $client, string $workspace, string $branch): void
    {
        $push = 'git -C ' . escapeshellarg($workspace) . $this->lendGitCredential(
            $client,
            $this->gitToken($this->session->agent),
            $this->credentialPath(),
            $this->remoteUrl
        );

        // A fully-qualified refspec, not `push --set-upstream origin <branch>`: `HEAD:refs/heads/<x>`
        // can only ever create or fast-forward that one branch, whatever the repository's push
        // defaults, remote config or refspecs happen to say.
        $this->runChecked(
            $client,
            $push . ' push origin ' . escapeshellarg('HEAD:refs/heads/' . $branch),
            'push ' . $branch,
            300
        );
    }

    private function credentialPath(): string
    {
        return '/tmp/kanvas-git-' . $this->session->uuid;
    }
}
