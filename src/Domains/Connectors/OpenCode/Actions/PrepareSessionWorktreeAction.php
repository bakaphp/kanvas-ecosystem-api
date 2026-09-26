<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Concerns\RunsCheckedSshCommands;
use Kanvas\Connectors\OpenCode\Concerns\UsesGitCredential;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\SshClient;

/**
 * Gives a session its own checkout of a repository, on the host, before the container starts.
 *
 * Two deliberate properties:
 *
 *  - **git runs on the host, never in the container.** The container gets a directory and no
 *    credential, so a prompt-injected agent has nothing to push with. That is the boundary; the prompt
 *    telling it not to push is only a courtesy.
 *  - **One bare mirror per (company, repo), one checkout per session.** Mirrors are never shared across
 *    companies — the disk is cheaper than the isolation question — and a checkout of its own means
 *    concurrent sessions on one repo never fight over a branch.
 */
class PrepareSessionWorktreeAction
{
    use RunsCheckedSshCommands;
    use UsesGitCredential;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly SshClient $client,
        private readonly string $cloneUrl,
        private readonly string $repoSlug,
        private readonly string $baseBranch = 'main',
        private readonly string $branchPrefix = 'agent/',
        /** Where mirrors live. Outside the container mount on purpose — see `mirrorPath()`. */
        private readonly string $root = '/srv/kanvas',
        /** The directory mounted into the agent's container; the checkout has to land inside it. */
        private readonly string $worktreeRoot = '/srv/kanvas/worktrees',
    ) {
    }

    public function execute(): AgentTaskSession
    {
        $mirror = $this->mirrorPath();
        $worktree = $this->worktreeRoot . '/' . $this->session->uuid;
        // A continuation keeps the branch it was given: the pull request is open against that name, and
        // pushing a second branch would strand the review on the first.
        $branch = Str::trimToNull($this->session->branch) ?? $this->branchPrefix . $this->session->task_id;

        $this->ensureMirror($mirror);
        $this->createCheckout($mirror, $worktree, $branch);

        $this->session->workspace_path = $worktree;
        $this->session->branch = $branch;
        $this->session->saveOrFail();

        return $this->session;
    }

    /**
     * Deliberately outside the worktree root, which is mounted into the container: the mirror holds
     * every branch this company has ever run against the repository, and an agent working on one task
     * has no business reading — or writing — the rest of them.
     */
    private function mirrorPath(): string
    {
        return $this->root . '/repos/' . $this->session->companies_id . '/' . $this->repoSlug . '.git';
    }

    /**
     * Authenticated, because a private repository is the normal case.
     *
     * Without the token git gets an anonymous request and GitHub answers "Repository not found" for a
     * private repo rather than "forbidden" — so the failure reads as a typo or a missing repository and
     * sends everyone looking in the wrong place.
     */
    private function ensureMirror(string $mirror): void
    {
        $credentialPath = '/tmp/kanvas-git-clone-' . $this->session->uuid;
        $credential = $this->lendGitCredential(
            $this->client,
            $this->gitToken($this->session->agent),
            $credentialPath,
            $this->cloneUrl
        );

        try {
            $exists = $this->client->exec(
                'test -d ' . escapeshellarg($mirror) . ' && echo EXISTS || echo MISSING',
                30
            );

            if (str_contains($exists, 'EXISTS')) {
                // Fetch rather than re-clone: the mirror is the expensive part and it is shared by
                // every session this company runs against the repo.
                $this->runChecked(
                    $this->client,
                    'git' . $credential . ' --git-dir=' . escapeshellarg($mirror) . ' fetch --prune origin',
                    'fetch the repository mirror',
                    600
                );

                return;
            }

            $this->runChecked($this->client, 'mkdir -p ' . escapeshellarg(dirname($mirror)), 'create the mirror directory');
            $this->runChecked(
                $this->client,
                'git' . $credential . ' clone --mirror '
                . escapeshellarg($this->cloneUrl) . ' ' . escapeshellarg($mirror),
                'clone the repository',
                900
            );
        } finally {
            $this->forgetGitCredential($this->client, $credentialPath);
        }
    }

    /**
     * A local clone, deliberately **not** `git worktree add`.
     *
     * A git worktree stores a `.git` *file* pointing at the parent repository's directory. Only the
     * checkout is mounted into the container, so that path does not exist in there and git is broken
     * from the agent's side — and because opencode snapshots the repository around every edit, the
     * edit fails while the model still reports success. A silently empty diff is the worst possible
     * failure here, so the checkout has to be self-contained.
     *
     * Cloning from the local mirror is cheap: git hardlinks the objects, so this costs a checkout
     * rather than a network fetch.
     */
    private function createCheckout(string $mirror, string $worktree, string $branch): void
    {
        $this->client->exec('rm -rf ' . escapeshellarg($worktree) . ' 2>&1 || true', 60);
        $this->runChecked($this->client, 'mkdir -p ' . escapeshellarg(dirname($worktree)), 'create the workspace directory');

        // Continuing means starting from the work already pushed, not from the base — otherwise the
        // follow-up silently reverts everything the first pass did.
        $startPoint = $this->hasBranch($mirror, $branch) ? $branch : $this->baseBranch;

        if ($this->hasBaseBranch($mirror, $startPoint)) {
            // `--branch <base>` against a --mirror source: a mirror reproduces the remote's ref
            // namespace, so its branches are plain `refs/heads/<name>` and there is no `origin/<name>`.
            $this->runChecked(
                $this->client,
                'git clone --branch ' . escapeshellarg($startPoint)
                . ' ' . escapeshellarg($mirror) . ' ' . escapeshellarg($worktree),
                'check out ' . $startPoint,
                600
            );

            // `-B`, not `-b`: continuing checks out a branch that already exists under that name.
            $this->runChecked(
                $this->client,
                'git -C ' . escapeshellarg($worktree) . ' checkout -B ' . escapeshellarg($branch),
                'create branch ' . $branch
            );
        } else {
            $this->startFirstCommit($worktree, $branch);
        }

        // origin must be the real remote, not the mirror we cloned from, or the push would land in a
        // cache on our own disk and look like it succeeded. `set-url` fails when there is no remote
        // yet, which is the case for a repository that had no commits to clone.
        $git = 'git -C ' . escapeshellarg($worktree) . ' remote ';
        $this->runChecked(
            $this->client,
            $git . 'set-url origin ' . escapeshellarg($this->cloneUrl)
            . ' 2>/dev/null || ' . $git . 'add origin ' . escapeshellarg($this->cloneUrl),
            'point origin at the repository'
        );
    }

    /**
     * A repository with no commits has no branches either, so there is nothing to clone or branch from.
     *
     * Distinguishing "empty" from "the configured base branch does not exist" matters: the first is a
     * brand-new repository and a perfectly good place to start, the second is a misconfigured
     * `base_branch` and should say so rather than silently starting from nothing.
     */
    private function hasBranch(string $mirror, string $branch): bool
    {
        return trim($this->client->exec(
            'git --git-dir=' . escapeshellarg($mirror) . ' rev-parse --verify --quiet '
            . escapeshellarg('refs/heads/' . $branch) . ' || true',
            60
        )) !== '';
    }

    private function hasBaseBranch(string $mirror, string $branch): bool
    {
        // `%(refname)` MUST be quoted: bash reads the parentheses as a subshell and dies on the syntax,
        // and since the whole line fails to parse the `2>/dev/null` never applies either — the error
        // text comes back as the output. That read as "this repository has branches, none called main",
        // which is the most misleading answer available.
        $refs = trim($this->client->exec(
            'git --git-dir=' . escapeshellarg($mirror)
            . ' for-each-ref --format=' . escapeshellarg('%(refname)') . ' refs/heads',
            60
        ));

        if ($refs === '') {
            return false;
        }

        $lines = array_values(array_filter(preg_split('/\r?\n/', $refs) ?: []));

        // Anything that is not a ref means the command itself failed. Guessing from it is how the
        // quoting bug above turned into a confident, wrong diagnosis.
        foreach ($lines as $line) {
            if (! str_starts_with($line, 'refs/heads/')) {
                throw new ValidationException('Could not list the repository\'s branches: ' . trim($refs));
            }
        }

        if (! in_array('refs/heads/' . $branch, $lines, true)) {
            throw new ValidationException(
                'The repository has no branch "' . $branch . '". Set base_branch on this '
                . 'repository in the agent\'s allow-list to one that exists.'
            );
        }

        return true;
    }

    /**
     * The first commit of an empty repository: a fresh checkout on the agent's branch, with nothing
     * behind it. The push then creates both the branch and the repository's history.
     */
    private function startFirstCommit(string $worktree, string $branch): void
    {
        $this->runChecked(
            $this->client,
            'git init -q -b ' . escapeshellarg($branch) . ' ' . escapeshellarg($worktree),
            'start a first checkout'
        );
    }
}
