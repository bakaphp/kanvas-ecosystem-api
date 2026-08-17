<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Contracts;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\DailyLearningSummary;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTask;
use Kanvas\Intelligence\AgentRuntime\DataTransferObject\KanbanTaskInput;
use Kanvas\Intelligence\AgentRuntime\Enums\HealthCheckResultEnum;
use Kanvas\Intelligence\AgentRuntime\Enums\KanbanTransition;
use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentBackup;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentMachine;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;

// One implementation per runtime backend (OpenClaw, Hermes, future Nano). Resolvers and
// services look the concrete up via AgentRuntimeProviderFactory. Partial implementations
// extend AbstractAgentRuntimeProvider and inherit default-throws for anything they don't
// support yet.
interface AgentRuntimeProvider
{
    public function name(): AgentProviderEnum;

    public function dispatchDeployment(
        Agent $agent,
        AgentMachine $machine,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentDeployment;

    public function dispatchTermination(AgentDeployment $deployment): void;

    public function dispatchRestart(AgentDeployment $deployment): void;

    // Re-materialize the running container's docker-compose.yml from current agent state and
    // recreate it with `docker compose up -d`. Needed after rotating channel credentials
    // (Slack/Telegram tokens) — those are baked into the compose file as env vars at launch,
    // and only a recreate (not a plain restart) applies the new values to the live process.
    public function dispatchCredentialSync(AgentDeployment $deployment): void;

    public function fetchContainerLogs(AgentDeployment $deployment, int $lines): string;

    // Parsed log entries — distinct from fetchContainerLogs, which returns the raw stdout dump.
    /** @return array<int, array{ts:string,level:string,msg:string,meta:string|null}> */
    public function fetchDeploymentLogs(AgentDeployment $deployment, int $limit): array;

    // Latest snapshot the background collector wrote, or null if none yet.
    // Cache key shape is provider-internal.
    /** @return array<string, mixed>|null */
    public function fetchTelemetrySnapshot(AgentDeployment $deployment): ?array;

    public function fetchContainerStatus(AgentDeployment $deployment): AgentDeployment;

    public function collectUsage(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
    ): AgentUsageSnapshot;

    public function execCommand(AgentDeployment $deployment, string $command, string $sessionId): bool;

    public function fetchConfig(AgentDeployment $deployment): string;

    public function updateConfig(AgentDeployment $deployment, string $config): bool;

    public function dispatchBackup(AgentDeployment $deployment, AgentBackup $backup, bool $includeWorkspace): void;

    public function createWorkspaceBackupNow(AgentDeployment $deployment, AgentBackup $backup): AgentBackup;

    public function dispatchMigrateWorkspace(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void;

    // Cross-runtime adoption: THIS provider takes over a deployment currently running under
    // a different runtime. Today only Hermes implements being a target (adopts OpenClaw).
    public function dispatchAdoptForeignDeployment(
        AgentDeployment $sourceDeployment,
        AgentMachine $destinationMachine,
        AppInterface $app,
        CompanyInterface $company,
        ?string $sourcePath,
        ?string $destinationPath,
    ): void;

    public function dispatchUpdateMachineContainers(AgentMachine $machine): void;

    public function dispatchWorkspaceUpdate(AgentDeployment $deployment): void;

    // Runtime liveness probe — drives the unified health-check cron + dashboard "is offline" pill.
    // Returns UNSUPPORTED for runtimes that don't expose a probe yet (default in the abstract);
    // OK/FAILED feed the 2-strike state machine in `BaseCheckHealthAction`.
    public function checkHealth(AgentDeployment $deployment): HealthCheckResultEnum;

    // Chat with the agent's live container deployment over its runtime HTTP API. Only the
    // container runtimes (OpenClaw, Hermes) implement this — in-process providers don't deploy
    // containers, so the abstract base default-throws.
    /**
     * @param list<string> $images URLs to forward as multimodal image content.
     * @param list<object> $additionalTools Kanvas tools injected for this turn only (the board
     *        toolset a wake job supplies). Machine runtimes ignore them — they cannot hold our
     *        tools; hosted runtimes bridge them back in-process.
     */
    public function chat(
        Agent $agent,
        string $message,
        ?string $sessionKey = null,
        array $images = [],
        array $additionalTools = [],
    ): string;

    // Pull the agent's conversation transcripts out of the runtime's per-deployment store
    // and persist into agent_conversations + agent_conversation_messages. Watermarked
    // incremental — concrete providers track the last imported message id in
    // agent_conversations.meta and ask the runtime only for newer rows. Returns the count
    // of newly persisted messages so callers can log / emit ledger events.
    //
    // $since is an optional override for the lookback floor; when null the provider uses
    // its own watermark (or a sane recent default for the first run).
    public function collectSessionTranscripts(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?Carbon $since = null,
    ): int;

    // Feeds yesterday's distilled learnings back into the agent's own memory bank so
    // the agent literally reads them on its next prompt — the loop-closing step of the
    // daily-learning system. Returns true if the push happened, false if the runtime
    // doesn't support it (NOT an error — Kanvas-side persistence still completed).
    //
    // Hermes: appends one-line facts to ~/.hermes/memories/MEMORY.md in §-separated
    // format, deduped against existing entries, FIFO-capped at ~80 facts.
    // OpenClaw: not yet implemented; chunked memory store requires the openclaw memory
    // CLI rather than markdown file write.
    public function pushDailyLearningContext(
        AgentDeployment $deployment,
        DailyLearningSummary $summary,
        Carbon $cycleDate,
    ): bool;

    // Read the agent's current durable-memory text so the summarize prompt
    // can tell the LLM "don't re-emit facts already in here". Returning the
    // raw file contents — the prompt builder owns the parsing/formatting.
    // Empty string is the documented "I have nothing to share" answer for
    // runtimes that don't support memory inspection (the contract default)
    // or for agents that haven't been written to yet.
    public function fetchDailyLearningContext(AgentDeployment $deployment): string;

    // --- Kanban board sync (Hermes today; default-throws elsewhere) ---------------------------
    // The runtime's multi-agent task board is mirrored into Kanvas NervousSystem Plans/Tasks.
    // Only container runtimes that ship a kanban implement these; the abstract base throws.

    // Read a board slice — by assignee (the agent's profile) and optionally a tenant/board.
    // Returns normalized KanbanTask DTOs (tree edges populated from the runtime's per-task view).
    // `knownTaskIds` = runtime task ids Kanvas already tracks; lets a runtime return only active cards
    // plus done cards it doesn't already know (catching a card created+completed between ticks) without
    // re-reading every historical done card each pass.
    /**
     * @param list<string> $knownTaskIds
     * @return list<KanbanTask>
     */
    public function fetchKanbanBoard(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        ?string $assignee = null,
        ?string $tenant = null,
        ?string $board = null,
        array $knownTaskIds = [],
    ): array;

    // Fetch a single card by id (assignee-agnostic) — used to refresh a card Kanvas already tracks
    // regardless of its current assignee. Null when the card no longer exists.
    public function fetchKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        ?string $board = null,
    ): ?KanbanTask;

    // Create a task. `input->idempotencyKey` (the Kanvas uuid) lets the runtime dedup so the
    // call is safe to retry; empty `parentIds` = a root task, otherwise a child under them.
    public function createKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        KanbanTaskInput $input,
        ?string $board = null,
    ): KanbanTask;

    // Apply a lifecycle verb and return the resulting task (re-read), so the caller can confirm
    // the move actually took — the runtime may reject an illegal transition. `reason` is for
    // BLOCK, `assignee` for ASSIGN, `result` for COMPLETE.
    public function transitionKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        KanbanTransition $transition,
        ?string $reason = null,
        ?string $assignee = null,
        ?string $result = null,
        ?string $board = null,
    ): KanbanTask;

    // Post a comment on a card. Comments are how a human steers a (re)spawned worker — the runtime
    // replays the full thread to the worker on each spawn. `author` tags provenance (`kanvas:<uid>`)
    // so the ingest can skip our own comments. Fire-and-forget — the runtime returns no JSON.
    public function commentKanbanTask(
        AgentDeployment $deployment,
        AppInterface $app,
        CompanyInterface $company,
        string $externalTaskId,
        string $text,
        string $author,
        ?string $board = null,
    ): void;
}
