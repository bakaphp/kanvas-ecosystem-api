<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\OpenClaw\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Agents\Models\AgentMachine;

trait HasOpenClawConfiguration
{
    public function setupOpenClawConfiguration(?Companies $company = null): void
    {
        $company = $company ?? auth()->user()->getCurrentCompany();

        $company->set(
            ConfigurationEnum::SSH_HOST->value,
            env('TEST_OPENCLAW_SSH_HOST', '')
        );
        $company->set(
            ConfigurationEnum::SSH_PORT->value,
            env('TEST_OPENCLAW_SSH_PORT', '22')
        );
        $company->set(
            ConfigurationEnum::SSH_USER->value,
            env('TEST_OPENCLAW_SSH_USER', '')
        );
        $company->set(
            ConfigurationEnum::SSH_PRIVATE_KEY->value,
            $this->resolvePrivateKey()
        );
        $company->set(
            ConfigurationEnum::OPENCLAW_HOME->value,
            env('TEST_OPENCLAW_HOME', '~/.openclaw')
        );
        $company->set(
            ConfigurationEnum::CLI_PATH->value,
            env('TEST_OPENCLAW_CLI_PATH', 'openclaw')
        );
        $company->set(
            ConfigurationEnum::CONFIG_FILENAME->value,
            env('TEST_OPENCLAW_CONFIG_FILENAME', 'openclaw.json')
        );
    }

    public function hasOpenClawCredentials(): bool
    {
        return ! empty(env('TEST_OPENCLAW_SSH_HOST'));
    }

    public function createTestMachine(?Companies $company = null): AgentMachine
    {
        $app = app(Apps::class);
        $company = $company ?? auth()->user()->getCurrentCompany();

        $machine = new AgentMachine();
        $machine->apps_id = $app->getId();
        $machine->companies_id = $company->getId();
        $machine->name = 'Test Machine ' . uniqid();
        $machine->host = env('TEST_OPENCLAW_SSH_HOST', '127.0.0.1');
        $machine->ssh_port = (int) env('TEST_OPENCLAW_SSH_PORT', 22);
        $machine->ssh_user = env('TEST_OPENCLAW_SSH_USER', 'root');
        $machine->ssh_private_key = $this->resolvePrivateKey();
        $machine->region = 'us-east-1';
        $machine->port_range_start = 20000;
        $machine->port_range_end = 30000;
        $machine->max_agents = 100;
        $machine->is_active = true;
        $machine->saveOrFail();

        return $machine;
    }

    /**
     * Resolve the SSH private key from env.
     * Supports: base64-encoded (with or without base64: prefix), file path, or inline key.
     */
    private function resolvePrivateKey(): string
    {
        $value = env('TEST_OPENCLAW_SSH_PRIVATE_KEY', 'test-key');

        if (str_starts_with($value, 'base64:')) {
            return base64_decode(substr($value, 7));
        }

        if (is_file($value)) {
            return file_get_contents($value);
        }

        // Detect raw base64: no spaces, no dashes at start, and decodes to a PEM key
        $decoded = base64_decode($value, true);
        if ($decoded !== false && str_starts_with($decoded, '-----BEGIN')) {
            return $decoded;
        }

        if (str_contains($value, '\\n')) {
            return str_replace('\\n', "\n", $value);
        }

        return $value;
    }
}
