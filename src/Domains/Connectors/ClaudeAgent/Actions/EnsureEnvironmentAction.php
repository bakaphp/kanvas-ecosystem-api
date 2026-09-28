<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\Enums\ConfigurationEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;

/**
 * Resolve the company's sandbox environment, creating it once.
 *
 * One per company: environment endpoints are capped at 60 RPM and 5 concurrent org-wide, so
 * provisioning per session would throttle the whole tenant.
 */
class EnsureEnvironmentAction
{
    use ResolvesClaudeClient;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
        protected readonly ?Client $client = null,
    ) {
    }

    public function execute(): string
    {
        $cached = trim((string) ($this->company->get(ConfigurationEnum::ENVIRONMENT_ID->value) ?? ''));

        if ($cached !== '') {
            return $cached;
        }

        $client = $this->claudeClient($this->app, $this->company);
        $name = $this->environmentName();

        try {
            $environment = $client->createEnvironment([
                'name' => $name,
                'config' => [
                    'type' => 'cloud',
                    'networking' => ['type' => 'unrestricted'],
                ],
            ]);
        } catch (ClaudeAgentApiException $e) {
            if ($e->status !== 409) {
                throw $e;
            }

            // We created it on an earlier run and lost the cached id (settings cleared, or a create
            // that succeeded remotely after we lost the response). Recover by name rather than leak
            // a second environment for the same company.
            $environment = $this->findByName($client, $name);
        }

        $environmentId = (string) ($environment['id'] ?? '');

        if ($environmentId === '') {
            throw new ClaudeAgentApiException('Claude Managed Agents returned an environment without an id.', 0);
        }

        $this->company->set(ConfigurationEnum::ENVIRONMENT_ID->value, $environmentId);

        return $environmentId;
    }

    /**
     * Deterministic so the 409 recovery can find it again, and tenant-scoped so a human reading the
     * vendor console can tell whose environment it is.
     */
    protected function environmentName(): string
    {
        return sprintf('kanvas-app-%d-company-%d', $this->app->getId(), $this->company->getId());
    }

    /**
     * @return array<string, mixed>
     */
    protected function findByName(Client $client, string $name): array
    {
        foreach ($client->listEnvironments()['data'] ?? [] as $environment) {
            if (is_array($environment) && ($environment['name'] ?? null) === $name) {
                return $environment;
            }
        }

        throw new ClaudeAgentApiException(
            "Claude Managed Agents rejected environment '{$name}' as duplicate but it is not listed.",
            409,
        );
    }
}
