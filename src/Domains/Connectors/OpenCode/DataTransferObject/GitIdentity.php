<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OpenCode\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Connectors\OpenCode\Enums\AgentCustomFieldEnum;
use Kanvas\Intelligence\Agents\Models\Agent;

/**
 * Who an agent's commits are authored by. Falls back to the agent itself, so `git log` attributes the
 * work honestly rather than to whatever identity happens to be configured on the machine.
 */
final readonly class GitIdentity
{
    public const string DEFAULT_EMAIL = 'agent@kanvas.dev';
    public const string DEFAULT_NAME = 'Kanvas agent';

    public function __construct(
        public string $name,
        public string $email,
    ) {
    }

    public static function forAgent(?Agent $agent): self
    {
        return new self(
            name: Str::trimToNull((string) $agent?->get(AgentCustomFieldEnum::GIT_AUTHOR_NAME->value))
                ?? Str::trimToNull((string) $agent?->name)
                ?? self::DEFAULT_NAME,
            email: Str::trimToNull((string) $agent?->get(AgentCustomFieldEnum::GIT_AUTHOR_EMAIL->value))
                ?? self::DEFAULT_EMAIL,
        );
    }

    public function isDefaultEmail(): bool
    {
        return $this->email === self::DEFAULT_EMAIL;
    }

    public function __toString(): string
    {
        return $this->name . ' <' . $this->email . '>';
    }
}
