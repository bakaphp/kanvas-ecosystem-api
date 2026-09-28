<?php

declare(strict_types=1);

namespace Database\Seeders;

use Override;

class AgentRuntimeEmailTemplateSeeder extends GlobalEmailTemplateSeeder
{
    private const array TEMPLATES = [
        'agent_deployment_launched',
        'agent_deployment_terminated',
        'agent_deployment_failed',
        'agent_deployment_missing_channel_integration',
        'agent_backup_result',
        'agent_migration_result',
    ];

    #[Override]
    protected function templates(): array
    {
        return collect(self::TEMPLATES)
            ->mapWithKeys(fn (string $name): array => [$name => 'views/emails/agent_runtime/' . $name . '.blade.php'])
            ->all();
    }
}
