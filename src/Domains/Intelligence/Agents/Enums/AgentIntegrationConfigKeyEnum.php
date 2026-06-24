<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum AgentIntegrationConfigKeyEnum: string
{
    case GOOGLE = 'integration_google';
    case JIRA = 'integration_jira';

    public static function fromName(string $name): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $name) {
                return $case;
            }
        }

        throw new \ValueError(sprintf('Unknown integration key: %s', $name));
    }
}
