<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mcp\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Mcp\DataTransferObject\McpServerConfig;
use Kanvas\Connectors\Mcp\Transports\GuardedHttpMcpTransport;
use Kanvas\Intelligence\Agents\Neuron\Tools\Mcp\CachedMcpConnector;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use NeuronAI\MCP\McpTransportInterface;
use Throwable;

/**
 * Everything needed to talk to one company's instance of one MCP server: is it switched on, what does
 * its descriptor say, and a connector wired to our guarded transport.
 *
 * `$app` is passed rather than derived from `$integration->app` — curated MCP rows sit at `apps_id=0`
 * and are shared by every app, so the row's own app relation is not the acting tenant.
 */
class McpConnectionService
{
    private ?McpServerConfig $config = null;

    /**
     * $transport is an injection point, not configuration: production always wants the guarded one, and
     * tests swap in NeuronAI's FakeMcpTransport so no suite ever opens a socket.
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Integrations $integration,
        private readonly ?McpTransportInterface $transport = null,
    ) {
    }

    public function config(): McpServerConfig
    {
        return $this->config ??= McpServerConfig::fromIntegration($this->integration);
    }

    public function integrationCompany(): ?IntegrationsCompany
    {
        return IntegrationsCompany::query()
            ->fromCompany($this->company)
            ->where('integrations_id', $this->integration->getId())
            ->where('is_active', 1)
            ->where('is_deleted', 0)
            ->with('status')
            ->first();
    }

    /**
     * A company that never connected the server, disabled it, or whose credential was rejected has no
     * capability here — the caller resolves no tools rather than dialling out to be told the same.
     */
    public function isEnabled(): bool
    {
        $row = $this->integrationCompany();

        if ($row === null) {
            return false;
        }

        return ($row->status->slug ?? null) === StatusEnum::ACTIVE->value;
    }

    public function connector(): CachedMcpConnector
    {
        return new CachedMcpConnector([
            'transport' => $this->transport ?? new GuardedHttpMcpTransport(
                url: $this->config()->url,
                appsId: $this->app->getId(),
                companiesId: $this->company->getId(),
                integrationsId: $this->integration->getId(),
                timeoutMs: $this->config()->timeoutMs,
                transport: $this->config()->transport->value,
            ),
        ]);
    }

    /**
     * A live `tools/list`, minus the platform denylist. Used by `setup()` to prove a credential and by
     * the cache layer on a miss — never called on a warm turn.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchDescriptors(): array
    {
        $tools = $this->connector()->fetchRemoteDescriptors();
        $config = $this->config();

        $tools = array_values(array_filter(
            $tools,
            fn (array $tool): bool => ! $config->isExcluded((string) ($tool['name'] ?? '')),
        ));

        // Deterministic order: an unstable tool list rewrites the LLM prompt prefix every turn and
        // throws away the provider's prompt cache even when nothing actually changed.
        usort($tools, fn (array $a, array $b): int => strcmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));

        return $tools;
    }

    public function markFailed(): void
    {
        try {
            $row = $this->integrationCompany();
            $failed = Status::where('slug', StatusEnum::FAILED->value)->where('apps_id', 0)->first();

            if ($row !== null && $failed !== null) {
                $row->setStatus($failed);
            }
        } catch (Throwable) {
            // Best effort: the caller is already degrading a turn, and failing to record why must not
            // turn that into an exception the agent surfaces.
        }
    }
}
