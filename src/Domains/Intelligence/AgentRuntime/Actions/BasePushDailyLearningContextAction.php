<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\Enums\MemoryFormatEnum;
use Kanvas\Intelligence\AgentRuntime\Services\MemoryBlockBuilderService;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use RuntimeException;
use Throwable;

// Read → dedup-and-append via MemoryBlockBuilderService → backup → write.
// Backups (`<file>.bak-{UTC ISO}`) protect against LLM-emitted corruption
// without needing container snapshots; no GC in v1, they're KB-scale.
//
// Returns false (not throws) on failure — Kanvas-side persistence already
// happened; the memory push is best-effort feedback.
abstract class BasePushDailyLearningContextAction
{
    public function __construct(
        protected readonly AgentDeployment $deployment,
        protected readonly DailyLearningSummary $summary,
        protected readonly Carbon $cycleDate,
    ) {
    }

    abstract protected function createSshClient(AgentMachine $machine): SshClient;

    abstract protected function resolveMemoryPath(): string;

    abstract protected function runtimeName(): string;

    // Which on-disk format the runtime's memory file uses. Hermes (the
    // default) reads MEMORY.md verbatim into the prompt and only needs
    // round-trippable text; OpenClaw chunks by markdown header so one
    // fact per `## N` block yields one chunk per fact.
    protected function memoryFormat(): MemoryFormatEnum
    {
        return MemoryFormatEnum::Separator;
    }

    // Optional post-write hook (reindex, cache bust, container reload).
    // Return value is logged but doesn't gate push success — the write
    // itself already landed by the time we get here.
    protected function afterWrite(SshClient $ssh): bool
    {
        return true;
    }

    public function execute(): bool
    {
        if ($this->summary->durable_facts === []) {
            // narrative briefing alone doesn't belong in MEMORY
            return false;
        }

        $machine = $this->deployment->machine;
        if ($machine === null) {
            Log::warning(sprintf('%s daily-learning push skipped: deployment has no machine', $this->runtimeName()), [
                'deployment_id' => $this->deployment->getId(),
            ]);

            return false;
        }

        $ssh = $this->createSshClient($machine);

        try {
            $path = $this->resolveMemoryPath();

            // Probe existence FIRST so we can distinguish "no file yet" from
            // "file exists but read returned empty" (perms/sudo issue — must
            // NOT overwrite or we silently destroy runtime-managed memory).
            $fileExists = $this->remoteFileHasContent($ssh, $path);
            $existing = $this->safeReadFile($ssh, $path);

            if ($fileExists && $existing === '') {
                Log::error(sprintf('%s daily-learning push aborted: file exists but read returned empty — refusing to overwrite (likely sudo cat NOPASSWD missing)', $this->runtimeName()), [
                    'deployment_id' => $this->deployment->getId(),
                    'path' => $path,
                ]);

                return false;
            }

            $built = new MemoryBlockBuilderService($this->memoryFormat())->build($existing, $this->summary);

            if ($built['added'] === 0) {
                Log::info(sprintf('%s daily-learning push: no new facts to add (all deduped)', $this->runtimeName()), [
                    'deployment_id' => $this->deployment->getId(),
                    'cycle_date' => $this->cycleDate->toDateString(),
                ]);

                return false;
            }

            // First push (no existing memory) needs no backup.
            $backupPath = null;
            if ($existing !== '') {
                $backupPath = $this->resolveBackupPath($path);
                $ssh->writeFileAsUser($backupPath, $existing, $this->deployment->system_user);
            }

            $ssh->writeFileAsUser($path, $built['content'], $this->deployment->system_user);

            $afterWriteOk = $this->afterWrite($ssh);

            Log::info(sprintf('%s daily-learning push: appended facts to memory file', $this->runtimeName()), [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'added' => $built['added'],
                'evicted' => $built['evicted'],
                'backup_path' => $backupPath,
                'after_write_ok' => $afterWriteOk,
            ]);

            return true;
        } catch (Throwable $e) {
            Log::error(sprintf('%s daily-learning push failed', $this->runtimeName()), [
                'deployment_id' => $this->deployment->getId(),
                'cycle_date' => $this->cycleDate->toDateString(),
                'error' => $e->getMessage(),
            ]);

            return false;
        } finally {
            $ssh->disconnect();
        }
    }

    // Compact ISO ("20260524T215030Z") so backups sort chronologically and
    // `ls -1` stays human-readable.
    protected function resolveBackupPath(string $memoryPath): string
    {
        return $memoryPath . '.bak-' . now('UTC')->format('Ymd\THis\Z');
    }

    // Sudo cat because runtime dotdirs are 0700; SFTP would silently return
    // '' and we'd treat existing memory as "first push" and overwrite it.
    protected function safeReadFile(SshClient $ssh, string $path): string
    {
        try {
            return $ssh->readFileAsUser($path);
        } catch (RuntimeException) {
            return '';
        }
    }

    // `sudo -n stat` not SFTP stat — parent dir may not be traversable. Stat
    // failure conservatively reports "doesn't exist"; the read attempt is
    // the authority.
    protected function remoteFileHasContent(SshClient $ssh, string $path): bool
    {
        $output = trim($ssh->exec(
            'sudo -n stat -c %s ' . escapeshellarg($path) . ' 2>/dev/null',
            10,
        ));

        return ctype_digit($output) && (int) $output > 0;
    }
}
