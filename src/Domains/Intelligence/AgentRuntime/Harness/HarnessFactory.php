<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness;

use Kanvas\Connectors\OpenCode\Client as OpenCodeClient;
use Kanvas\Connectors\OpenCode\OpenCodeHarness;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\AgentHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\CodingHarness;
use Kanvas\Intelligence\AgentRuntime\Harness\Contracts\HarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\HarnessEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\MachineNetworkModeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\Intelligence\AgentRuntime\Harness\Transports\HttpHarnessTransport;
use Kanvas\Intelligence\AgentRuntime\Harness\Transports\SshExecHarnessTransport;

/**
 * Resolves the harness for a session. Built fresh every time — a transport holds an HTTP client, and
 * the poller that uses this is a queued job, where a cached handler stack would not survive
 * serialization.
 */
class HarnessFactory
{
    public static function forSession(AgentTaskSession $session, ?HarnessTransport $transport = null): AgentHarness
    {
        return match ($session->harnessName()) {
            HarnessEnum::OPENCODE => new OpenCodeHarness(
                new OpenCodeClient($transport ?? self::transportFor($session))
            ),
            HarnessEnum::PIDEV, HarnessEnum::CLAUDE => throw new ValidationException(
                'Harness ' . $session->harness . ' is not behind the AgentHarness contract yet; '
                . 'use its own connector actions.'
            ),
        };
    }

    public static function codingHarnessForSession(
        AgentTaskSession $session,
        ?HarnessTransport $transport = null
    ): CodingHarness {
        $harness = self::forSession($session, $transport);

        if (! $harness instanceof CodingHarness) {
            throw new ValidationException('Harness ' . $session->harness . ' cannot report a diff');
        }

        return $harness;
    }

    /**
     * How the request reaches the container, which is a property of the MACHINE rather than of the
     * session: a runtime on a shared Docker network or a private address is addressable, and one on a
     * customer's box usually is not. The `ssh_exec` case is the reason `HarnessTransport` exists.
     */
    private static function transportFor(AgentTaskSession $session): HarnessTransport
    {
        if ($session->endpoint === null || $session->endpoint === '') {
            throw new ValidationException('Session ' . $session->uuid . ' has no endpoint; it was never provisioned');
        }

        $machine = $session->machine;

        if ($machine !== null && $machine->network_mode === MachineNetworkModeEnum::SSH_EXEC->value) {
            if ($session->container_name === null || $session->container_name === '') {
                throw new ValidationException(
                    'Session ' . $session->uuid . ' runs over ssh but has no container to exec into.'
                );
            }

            return new SshExecHarnessTransport(
                machine: $machine,
                container: $session->container_name,
                password: (string) $session->server_password,
            );
        }

        return new HttpHarnessTransport(
            baseUrl: $session->endpoint,
            password: (string) $session->server_password,
        );
    }
}
