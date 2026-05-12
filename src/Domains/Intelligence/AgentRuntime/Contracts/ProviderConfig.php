<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Contracts;

/**
 * Immutable value object capturing all variation points between agent runtime providers.
 *
 * Passed into every shared base class so no provider-specific strings are
 * hardcoded in the AgentRuntime layer. Each concrete provider (OpenClaw, Hermes)
 * returns an instance of this DTO from its SshClient::makeProviderConfig().
 */
final readonly class ProviderConfig
{
    public function __construct(
        /** Provider identifier stored in agent_deployments.provider ('openclaw' | 'hermes') */
        public string $providerName,
        /** Prefix for container_name field ('openclaw-' | 'hermes-') */
        public string $containerPrefix,
        /** Laravel queue name for this provider's jobs. Both providers currently share 'agent-runtime' — split when a provider's job volume warrants its own worker fleet. */
        public string $queueName,
        /** Hidden dot-directory name on the host ('..openclaw' | '.hermes') */
        public string $dotDir,
        /** Runtime config filename ('openclaw.json' | 'hermes.json') */
        public string $configFilename,
        /** Path to the dot-directory inside the container ('/home/node/.openclaw' | '/home/node/.hermes') */
        public string $containerHomeDotDir,
        /** Full command used to invoke the runtime inside the container ('node /app/openclaw.mjs' | 'node /app/hermes.mjs') */
        public string $mjsPath,
        /** CLI alias used on the host for legacy non-Docker deployments ('openclaw' | 'hermes') */
        public string $cliAlias,
        /** Default shared Docker image name when app config is absent ('openclaw-kanvas:latest' | 'hermes-kanvas:latest') */
        public string $defaultSharedImageName,
        /** Default shared Docker image build directory when app config is absent ('/opt/openclaw-image' | '/opt/hermes-image') */
        public string $defaultSharedImageDir,
        /** docker-compose.yml placeholder for the agent's home dot-dir ('{{OPENCLAW_DIR}}' | '{{HERMES_DIR}}') */
        public string $dirPlaceholder,
        /** AgentDeployment custom-field key for the gateway token ('OPENCLAW_GATEWAY_TOKEN' | 'HERMES_GATEWAY_TOKEN') */
        public string $gatewayTokenCustomFieldKey,
        /** Agent custom-field key for the deployment id ('OPENCLAW_DEPLOYMENT_ID' | 'HERMES_DEPLOYMENT_ID') */
        public string $deploymentIdCustomFieldKey,
        /** Company config key for a pre-set gateway token ('openclaw_gateway_token' | 'hermes_gateway_token') */
        public string $gatewayTokenConfigKey,
    ) {
    }
}
