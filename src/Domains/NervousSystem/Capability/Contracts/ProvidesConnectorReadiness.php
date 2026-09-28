<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Capability\Contracts;

use Baka\Contracts\AppInterface;
use Kanvas\NervousSystem\Capability\DataTransferObject\ConnectorReadiness;

/**
 * One implementation per connector that an agent's tools depend on.
 *
 * Deliberately a **configuration** check, not a liveness probe: `capability_lookup` runs this inline
 * during a turn, so a set of network round-trips would make the tool too slow to reach for and too
 * flaky to trust. A connector that also wants a live probe keeps it in its own tool — see
 * `CheckCodingSetupTool`, which pings pi.dev because dispatching to a dead server is worth the wait.
 */
interface ProvidesConnectorReadiness
{
    /** Stable identifier, e.g. `google-sheets`. */
    public function slug(): string;

    /** Human label used in agent-facing copy, e.g. `Google Sheets`. */
    public function label(): string;

    /**
     * Tool-catalog areas this connector backs — the folder segment after `Tools\` in a handler's
     * namespace, which is how a catalog row is traced back to the connector it needs. A connector
     * with tools in both the Neuron and Laravel trees still has one area name.
     *
     * @return list<string>
     */
    public function toolAreas(): array;

    public function readiness(AppInterface $app): ConnectorReadiness;
}
