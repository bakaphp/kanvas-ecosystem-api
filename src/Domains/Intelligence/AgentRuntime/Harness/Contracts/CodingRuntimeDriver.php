<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Contracts;

use Baka\Contracts\AppInterface;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\RuntimeHandle;
use Kanvas\Intelligence\AgentRuntime\SshClient;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * The per-agent opencode runtime on a machine: a container on a Linux box, a host process on a Mac.
 *
 * One implementation per `MachineRuntimeEnum`, built with the machine it serves. Everything above
 * this interface — provision, stop, kill, reap, replace — asks it rather than running `docker`
 * itself, so the Mac's launchd job and the Linux container are the same thing to the rest of the
 * harness: a handle, an endpoint, a password and a worktree root.
 */
interface CodingRuntimeDriver
{
    public function handleFor(Agent $agent): string;

    public function ensure(Agent $agent, SshClient $client): RuntimeHandle;

    /**
     * True once the handle is gone; false when it is still there after the removal grace period.
     */
    public function remove(string $handle, SshClient $client): bool;

    /**
     * Every runtime on the box that carries an agent label, ours or not. Callers intersect with the
     * sessions table to tell which are theirs.
     *
     * @return list<array{handle: string, agentId: int, version: string}>
     */
    public function list(SshClient $client): array;

    /**
     * Installs the pinned runtime ahead of the first task; returns what it installed.
     */
    public function prewarm(AppInterface $app, SshClient $client): string;

    /**
     * @return list<string> problems in the readiness service's sentence shape
     */
    public function hostProblems(AppInterface $app, SshClient $client): array;

    /**
     * The form a listed `version` must equal to be current, given the image tag an operator passed:
     * the tag itself for a container, the version after the colon for a host binary.
     */
    public function versionFor(string $imageTag): string;

    /**
     * Periodic upkeep the reaper runs on its pass; returns one line per thing it reclaimed (a
     * truncated log, a deleted simulator), for the reaper's report.
     *
     * @return list<string>
     */
    public function housekeep(AppInterface $app, SshClient $client): array;

    /**
     * Directories outside the agent tree that the runtime fills and the reaper should measure, by
     * label: a host's Xcode caches live in the login user's Library, not under `{root}/agents`.
     *
     * @return array<string, list<string>>
     */
    public function extraDiskPaths(SshClient $client): array;
}
