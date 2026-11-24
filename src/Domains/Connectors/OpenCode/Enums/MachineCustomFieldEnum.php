<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\Enums;

/**
 * Custom fields on an `AgentMachine` that the coding runtime reads or writes.
 */
enum MachineCustomFieldEnum: string
{
    /** What the machine actually runs under the pinned version: an image id, or a binary's sha256. */
    case IMAGE_DIGEST = 'CODING_IMAGE_DIGEST';

    /** JSON list of tool sets a host advertises (`["xcode"]`); gates the allow-list and the readiness rows. */
    case HOST_TOOLS = 'CODING_HOST_TOOLS';
}
