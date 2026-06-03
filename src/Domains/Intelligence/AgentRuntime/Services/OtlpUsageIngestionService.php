<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Services;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\AgentRuntime\Actions\BaseCollectDeploymentUsageAction;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\Intelligence\Agents\Models\AgentUsageSnapshot;
use Throwable;

/**
 * Parse an OTLP/HTTP metrics payload (JSON encoding) and persist token usage
 * into agent_usage_snapshots.
 *
 * The OTel Collector's spanmetrics connector produces one metric per span
 * operation with the resource attributes attached. We look for metrics whose
 * names carry the gen_ai.usage.* prefix (e.g. `gen_ai.usage.input_tokens`)
 * and aggregate their data-point values per deployment.
 *
 * Expected payload shape (OTLP metrics JSON):
 * {
 *   "resourceMetrics": [{
 *     "resource": {
 *       "attributes": [
 *         {"key": "agent.deployment_id", "value": {"stringValue": "42"}},
 *         {"key": "agent.container_name", "value": {"stringValue": "openclaw-42"}},
 *         {"key": "gen_ai.request.model",  "value": {"stringValue": "claude-sonnet-4-5"}}
 *       ]
 *     },
 *     "scopeMetrics": [{
 *       "metrics": [{
 *         "name": "gen_ai.usage.input_tokens",
 *         "gauge": { "dataPoints": [{"asInt": "1240"}] }
 *       }, ...]
 *     }]
 *   }]
 * }
 */
class OtlpUsageIngestionService
{
    public function ingest(array $payload): bool
    {
        /** @var array<int, array<string, mixed>> $resourceMetrics */
        $resourceMetrics = $payload['resourceMetrics'] ?? [];

        if (empty($resourceMetrics)) {
            return true;
        }

        $succeeded = 0;
        $failed = 0;

        foreach ($resourceMetrics as $rm) {
            try {
                $this->processResourceMetrics($rm);
                $succeeded++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('OtlpUsageIngestionService: failed to process resource metrics — ' . $e->getMessage(), [
                    'resource' => $rm['resource'] ?? null,
                ]);
            }
        }

        if ($failed > 0) {
            Log::warning("OtlpUsageIngestionService: {$failed} resource(s) failed, {$succeeded} succeeded.");
        }

        return $failed === 0;
    }

    /**
     * @param array<string, mixed> $rm  One element of resourceMetrics[]
     */
    private function processResourceMetrics(array $rm): void
    {
        $attrs = $this->extractAttributes($rm['resource']['attributes'] ?? []);

        $deploymentId = $attrs['agent.deployment_id'] ?? null;
        $containerName = $attrs['agent.container_name'] ?? null;

        if (empty($deploymentId) && empty($containerName)) {
            return; // Cannot resolve a deployment — skip silently
        }

        $deployment = $this->resolveDeployment($deploymentId, $containerName);

        if ($deployment === null) {
            Log::debug("OtlpUsageIngestionService: no deployment found for id={$deploymentId} container={$containerName}");

            return;
        }

        // Aggregate token counts across all scopeMetrics
        $tokens = [
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cache_read_tokens' => 0,
            'cache_write_tokens' => 0,
        ];

        $model = $attrs['gen_ai.request.model'] ?? null;
        $system = $attrs['gen_ai.system'] ?? null;

        foreach ($rm['scopeMetrics'] ?? [] as $scope) {
            foreach ($scope['metrics'] ?? [] as $metric) {
                $name = $metric['name'] ?? '';
                $value = $this->extractFirstDataPointValue($metric);

                match ($name) {
                    'gen_ai.usage.input_tokens'        => $tokens['input_tokens'] += $value,
                    'gen_ai.usage.output_tokens'       => $tokens['output_tokens'] += $value,
                    'gen_ai.usage.total_tokens'        => $tokens['total_tokens'] += $value,
                    'gen_ai.usage.cache_read_tokens'   => $tokens['cache_read_tokens'] += $value,
                    'gen_ai.usage.cache_write_tokens'  => $tokens['cache_write_tokens'] += $value,
                    default                            => null,
                };

                // Extract model/system from metric attributes if not in resource
                if ($model === null || $system === null) {
                    $metricAttrs = $this->extractAttributes($metric['gauge']['dataPoints'][0]['attributes'] ?? []);
                    $model ??= $metricAttrs['gen_ai.request.model'] ?? null;
                    $system ??= $metricAttrs['gen_ai.system'] ?? null;
                }
            }
        }

        // If total_tokens wasn't emitted separately, compute it
        if ($tokens['total_tokens'] === 0 && ($tokens['input_tokens'] + $tokens['output_tokens']) > 0) {
            $tokens['total_tokens'] = $tokens['input_tokens'] + $tokens['output_tokens'];
        }

        // Nothing useful to store
        if ($tokens['total_tokens'] === 0) {
            return;
        }

        $provider = $deployment->provider
            ?? ($system !== null ? $system : null)
            ?? ($model !== null ? BaseCollectDeploymentUsageAction::inferLlmProvider($model) : null);

        try {
            $app = Apps::getById($deployment->apps_id);
            $company = Companies::getById($deployment->companies_id);
        } catch (Throwable) {
            Log::warning("OtlpUsageIngestionService: could not load app/company for deployment #{$deployment->getId()}");

            return;
        }

        $source = ($provider !== null ? strtolower($provider) : 'agent') . '_otel';

        AgentUsageSnapshot::updateOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'agent_deployment_id' => $deployment->getId(),
                'snapshot_date' => now()->toDateString(),
                'source' => $source,
            ],
            [
                'input_tokens' => $tokens['input_tokens'],
                'output_tokens' => $tokens['output_tokens'],
                'total_tokens' => $tokens['total_tokens'],
                'cache_read_tokens' => $tokens['cache_read_tokens'],
                'cache_write_tokens' => $tokens['cache_write_tokens'],
                'provider' => $provider,
                'model' => $model,
                'total_sessions' => 0, // Sessions tracked separately via telemetry service
                'raw_output' => null,
                'parsed_data' => $tokens,
            ]
        );
    }

    /**
     * Resolve a deployment by ID first, falling back to container_name.
     */
    private function resolveDeployment(?string $deploymentId, ?string $containerName): ?AgentDeployment
    {
        if (! empty($deploymentId)) {
            /** @var AgentDeployment|null $deployment */
            $deployment = AgentDeployment::find((int) $deploymentId);

            if ($deployment !== null) {
                return $deployment;
            }
        }

        if (! empty($containerName)) {
            return AgentDeployment::where('container_name', $containerName)
                ->where('is_deleted', 0)
                ->first();
        }

        return null;
    }

    /**
     * Convert OTLP attribute list [{"key":"k","value":{"stringValue":"v"}}]
     * into a flat key→value map.
     *
     * @param  array<int, array<string, mixed>> $attributes
     * @return array<string, string>
     */
    private function extractAttributes(array $attributes): array
    {
        $result = [];

        foreach ($attributes as $attr) {
            $key = $attr['key'] ?? '';

            if ($key === '') {
                continue;
            }

            $valueWrapper = $attr['value'] ?? [];
            $value = $valueWrapper['stringValue']
                ?? (string) ($valueWrapper['intValue'] ?? $valueWrapper['doubleValue'] ?? '');

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Extract the first data-point value from a gauge or sum metric.
     * Handles both intValue (string) and doubleValue.
     */
    private function extractFirstDataPointValue(array $metric): int
    {
        $dataPoints = $metric['gauge']['dataPoints']
            ?? $metric['sum']['dataPoints']
            ?? [];

        if (empty($dataPoints)) {
            return 0;
        }

        $dp = $dataPoints[0];

        return (int) ($dp['asInt'] ?? $dp['asDouble'] ?? 0);
    }
}
