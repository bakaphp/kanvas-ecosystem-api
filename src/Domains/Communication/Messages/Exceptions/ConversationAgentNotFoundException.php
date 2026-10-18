<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Exceptions;

use RuntimeException;

/**
 * A company whose conversations are answered automatically has no agent to answer them with — a
 * setup fault someone has to fix, so it is reported rather than skipped.
 */
final class ConversationAgentNotFoundException extends RuntimeException
{
}
