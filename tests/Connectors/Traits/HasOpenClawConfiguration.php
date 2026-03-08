<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;

trait HasOpenClawConfiguration
{
    public function setupOpenClawConfiguration(?Companies $company = null): void
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        $company->set(
            ConfigurationEnum::SSH_HOST->value,
            env('TEST_OPENCLAW_SSH_HOST', '127.0.0.1')
        );
        $company->set(
            ConfigurationEnum::SSH_PORT->value,
            env('TEST_OPENCLAW_SSH_PORT', '22')
        );
        $company->set(
            ConfigurationEnum::SSH_USER->value,
            env('TEST_OPENCLAW_SSH_USER', 'openclaw')
        );
        $company->set(
            ConfigurationEnum::SSH_PRIVATE_KEY->value,
            env('TEST_OPENCLAW_SSH_PRIVATE_KEY', '')
        );
        $company->set(
            ConfigurationEnum::OPENCLAW_HOME->value,
            env('TEST_OPENCLAW_HOME', '/opt/openclaw')
        );
    }

    public function hasOpenClawCredentials(): bool
    {
        return ! empty(env('TEST_OPENCLAW_SSH_HOST'))
            && ! empty(env('TEST_OPENCLAW_SSH_PRIVATE_KEY'));
    }
}
