<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Enums;

enum MachineNetworkModeEnum: string
{
    /** Container joins a Docker network the API is also on; nothing is published, addressed by name. */
    case SHARED_NETWORK = 'shared_network';

    /** Published on the machine's private interface only; addressed by IP and port. */
    case PRIVATE_IP = 'private_ip';

    /** No route from the API at all — the fallback, where every call rides `docker exec` over SSH. */
    case SSH_EXEC = 'ssh_exec';

    /**
     * Only `private_ip` needs a port, and it is the only mode where two sessions can collide over one —
     * which is why the allocator exists on that path and nowhere else.
     */
    public function needsPublishedPort(): bool
    {
        return $this === self::PRIVATE_IP;
    }

    public function isDirectlyRoutable(): bool
    {
        return $this !== self::SSH_EXEC;
    }
}
