<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Contracts;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessPrompt;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\HarnessTick;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\PermissionDecisionEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;

/**
 * One implementation per agent backend. Kanvas owns the task, the credentials, the workspace and the
 * decisions; the harness owns only the reasoning loop, which is what makes it replaceable.
 */
interface AgentHarness
{
    public function name(): HarnessEnum;

    /**
     * Opens the remote session and sends the first prompt. Returns with the work running — never
     * blocks for the turn, or a 30-minute task would hold a queue worker.
     */
    public function start(AgentTaskSession $session, HarnessPrompt $prompt): void;

    /**
     * Additive correction that joins the current turn. For a change of direction call `stop()` first
     * and start again: a steer does not undo work already done.
     */
    public function steer(AgentTaskSession $session, string $message): void;

    /** Queued behind the turn in flight — for a supervisor's question, not an instruction. */
    public function ask(AgentTaskSession $session, string $message): void;

    public function poll(AgentTaskSession $session): HarnessTick;

    public function answerPermission(
        AgentTaskSession $session,
        string $permissionId,
        PermissionDecisionEnum $decision
    ): void;

    public function answerQuestion(AgentTaskSession $session, string $questionId, string $answer): void;

    public function stop(AgentTaskSession $session): void;
}
