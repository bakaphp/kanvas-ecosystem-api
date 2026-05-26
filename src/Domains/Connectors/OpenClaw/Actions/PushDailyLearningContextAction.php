<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenClaw\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Hermes\Services\HermesMemoryBlockBuilderService;
use Kanvas\Connectors\OpenClaw\SshClient;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use RuntimeException;
use Throwable;

/**
 * OpenClaw daily-learning push. Mirrors the Hermes loop:
 * read → dedup-and-append → backup → write — with two OpenClaw-specific
 * adjustments:
 *
 *  1. Target file is `~/.openclaw/workspace/memory/KANVAS-LEARNINGS.md`,
 *     a Kanvas-owned namespace next to OpenClaw's own `YYYY-MM-DD.md` files.
 *     We deliberately don't write into those — they're owned by OpenClaw's
 *     own `memory promote --apply` pipeline.
 *  2. After write we run `openclaw memory index --force` inside the
 *     container so the chunk / vector / FTS indexes pick up our changes.
 *     Hermes uses the raw .md directly in context; OpenClaw consults the
 *     index, so a stale index would mean the agent never reads the new
 *     facts.
 *
 * Returns false (not throws) on any failure — Kanvas-side persistence has
 * already happened; the memory push is best-effort feedback.
 */
class PushDailyLearningContextAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
        protected readonly DailyLearningSummary $summary,
        protected readonly Carbon $cycleDate,
    ) {
    }

    public function execute(): bool
    {
        if ($this->summary->durable_facts === []) {
            // Nothing durable to push — narrative briefing alone doesn't
            // belong in the durable memory file.
            return false;
        }

        $machine = $this->deployment->machine;
        if ($machine === null) {
            Log::warning('OpenClaw daily-learning push skipped: deployment has no machine', [
                'deployment_id' => $this->deployment->getId(),
            ]);

            return false;
        }

        $ssh = SshClient::fromMachine($machine);

        try {
            $path = $this->resolveMemoryPath();

            // Probe existence FIRST so we can distinguish "no file yet" (first
            // push, safe to write fresh) from "file exists but our read returned
            // empty" (perms/sudo issue — must NOT overwrite or we silently
            // destroy a Kanvas-managed memory file).
            $fileExists = $this->remoteFileHasContent($ssh, $path);
            $existing = $this->safeReadFile($ssh, $path);

            if ($fileExists && $existing === '') {
                Log::error('OpenClaw daily-learning push aborted: file exists but read returned empty — refusing to overwrite', [
                    'deployment_id' => $this->deployment->getId(),
                    'path' => $path,
                ]);

                return false;
            }

            $built = new HermesMemoryBlockBuilderService()->build($existing, $this->summary);

            if ($built['added'] === 0) {
                Log::info('OpenClaw daily-learning push: no new facts to add (all deduped)', [
                    'deployment_id' => $this->deployment->getId(),
                    'cycle_date' => $this->cycleDate->toDateString(),
                ]);

                return false;
            }

            // Backup before overwrite — only when the file already exists.
            // First push (no existing memory) needs no backup.
            $backupPath = null;
            if ($existing !== '') {
                $backupPath = $this->resolveBackupPath($path);
                $ssh->writeFileAsUser($backupPath, $existing, $this->deployment->system_user);
            }

            $ssh->writeFileAsUser($path, $built['content'], $this->deployment->system_user);

            $reindexed = $this->reindexMemory($ssh);

            Log::info('OpenClaw daily-learning push: appended facts to KANVAS-LEARNINGS.md', [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'added' => $built['added'],
                'evicted' => $built['evicted'],
                'backup_path' => $backupPath,
                'reindexed' => $reindexed,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error('OpenClaw daily-learning push failed', [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $ssh->disconnect();
        }
    }

    private function resolveMemoryPath(): string
    {
        $home = $this->deployment->home_directory !== ''
            ? $this->deployment->home_directory
            : '/home/' . $this->deployment->system_user;

        return rtrim($home, '/') . '/.openclaw/workspace/memory/KANVAS-LEARNINGS.md';
    }

    /**
     * Backup sibling, suffixed with the UTC instant the write happens.
     * Compact ISO ("20260524T215030Z") so the directory sorts chronologically
     * and `ls -1` stays human-readable.
     */
    private function resolveBackupPath(string $memoryPath): string
    {
        return $memoryPath . '.bak-' . now('UTC')->format('Ymd\THis\Z');
    }

    /**
     * Read via `sudo cat` because `.openclaw/` is 0700 owned by the
     * container's agent UID — SFTP as the SSH user would hit Permission
     * denied and silently return empty. Symmetric with the write path's
     * `sudo tee` via `writeFileAsUser`.
     */
    private function safeReadFile(SshClient $ssh, string $path): string
    {
        try {
            return $ssh->readFileAsUser($path);
        } catch (RuntimeException) {
            return '';
        }
    }

    /**
     * Cheap pre-flight: does the file exist on the remote with non-zero
     * size? `sudo -n stat` rather than SFTP stat because the parent dir
     * may not be traversable by the SSH user. If stat fails for any reason
     * we conservatively report "doesn't exist" — the caller's read attempt
     * is the authority; this is just a sanity check to catch the case where
     * read silently degrades to '' on a file that actually has content.
     */
    private function remoteFileHasContent(SshClient $ssh, string $path): bool
    {
        $output = trim($ssh->exec(
            'sudo -n stat -c %s ' . escapeshellarg($path) . ' 2>/dev/null',
            10,
        ));

        return ctype_digit($output) && (int) $output > 0;
    }

    /**
     * Rebuild the OpenClaw chunk + vector + FTS indexes so the freshly
     * appended facts become searchable on the agent's next memory query.
     * `--force` ensures a full reindex even when openclaw thinks the file
     * isn't dirty (it tracks files; we just wrote one).
     *
     * Returns false on failure but does not throw — the file write
     * succeeded, the index will catch up on the next openclaw run.
     */
    private function reindexMemory(SshClient $ssh): bool
    {
        $container = $this->deployment->container_name;
        if ($container === '') {
            return false;
        }

        try {
            $cmd = 'docker exec ' . escapeshellarg($container)
                . ' timeout 60 openclaw memory index --force 2>&1';
            $output = $ssh->exec($cmd, 90);

            // openclaw exits 0 on success; we don't have an exit code here,
            // but error output contains "Error:" / "Failed" — log for ops.
            if (str_contains($output, 'Error:') || str_contains($output, 'Failed')) {
                Log::warning('OpenClaw memory index reported errors', [
                    'deployment_id' => $this->deployment->getId(),
                    'output' => substr($output, 0, 500),
                ]);

                return false;
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('OpenClaw memory index failed', [
                'deployment_id' => $this->deployment->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
