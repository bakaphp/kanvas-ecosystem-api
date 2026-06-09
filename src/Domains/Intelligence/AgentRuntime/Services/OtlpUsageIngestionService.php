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
 * Parse an OTLP/HTTP traces payload (JSON encoding) and persist token usage
 * into agent_usage_snapshots.
 *
 * The OTel Collector forwards raw spans (no spanmetrics aggregation) and we
 * extract gen_ai.usage.* attributes directly from each span. Token counts are
 * summed per deployment per day and upserted.
 *
 * Expected payload shape (OTLP traces JSON):
 * {
 *   "resourceSpans": [{
 *     "resource": {
 *       "attributes": [
 *         {"key": "agent.deployment_id", "value": {"stringValue": "42"}},
 *         {"key": "agent.container_name", "value": {"stringValue": "openclaw-42"}}
 *       ]
 *     },
 *     "scopeSpans": [{
 *       "spans": [{
 *         "attributes": [
 *           {"key": "gen_ai.system",               "value": {"stringValue": "anthropic"}},
 *           {"key": "gen_ai.request.model",         "value": {"stringValue": "claude-sonnet-4-5"}},
 *           {"key": "gen_ai.usage.input_tokens",    "value": {"intValue": "820"}},
 *           {"key": "gen_ai.usage.output_tokens",   "value": {"intValue": "210"}},
 *           {"key": "gen_ai.usage.total_tokens",    "value": {"intValue": "1030"}}
 *         ]
 *       }]
 *     }]
 *   }]
 * }
 */
class OtlpUsageIngestionService
{
    public function ingest(array $payload): bool
    {
        // Support both OTLP traces format (resourceSpans) and metrics format (resourceMetrics).
        // The collector now forwards raw spans so we primarily use resourceSpans.
        $resourceSpans = $payload['resourceSpans'] ?? [];

        if (! empty($resourceSpans)) {
            return $this->ingestTraces($resourceSpans);
        }

        // Legacy metrics path — kept for backward compat with direct API calls
        $resourceMetrics = $payload['resourceMetrics'] ?? [];

        if (! empty($resourceMetrics)) {
            return $this->ingestMetrics($resourceMetrics);
        }

        return true;
    }

    /**
     * Parse OTLP traces format: resourceSpans[].scopeSpans[].spans[].attributes
     *
     * @param  array<int, array<string, mixed>> $resourceSpans
     */
    private function ingestTraces(array $resourceSpans): bool
    {
        $succeeded = 0;
        $failed = 0;

        // Aggregate tokens per deployment across all spans in this batch
        /** @var array<string, array{deployment_id: string, container_name: string, model: ?string, system: ?string, tokens: array<string, int>}> $byDeployment */
        $byDeployment = [];

        foreach ($resourceSpans as $rs) {
            $resourceAttrs = $this->extractAttributes($rs['resource']['attributes'] ?? []);
            $deploymentKey = $resourceAttrs['agent.deployment_id'] ?? $resourceAttrs['agent.container_name'] ?? null;

            if ($deploymentKey === null) {
                continue;
            }

            if (! isset($byDeployment[$deploymentKey])) {
                $byDeployment[$deploymentKey] = [
                    'deployment_id' => $resourceAttrs['agent.deployment_id'] ?? null,
                    'container_name' => $resourceAttrs['agent.container_name'] ?? null,
                    'model' => null,
                    'system' => null,
                    'tokens' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0],
                ];
            }

            foreach ($rs['scopeSpans'] ?? [] as $scope) {
                foreach ($scope['spans'] ?? [] as $span) {
                    $spanAttrs = $this->extractAttributes($span['attributes'] ?? []);

                    $byDeployment[$deploymentKey]['model'] ??= $spanAttrs['gen_ai.request.model'] ?? null;
                    $byDeployment[$deploymentKey]['system'] ??= $spanAttrs['gen_ai.system'] ?? null;

                    $t = &$byDeployment[$deploymentKey]['tokens'];
                    $t['input_tokens']       += (int) ($spanAttrs['gen_ai.usage.input_tokens'] ?? 0);
                    $t['output_tokens']      += (int) ($spanAttrs['gen_ai.usage.output_tokens'] ?? 0);
                    $t['total_tokens']       += (int) ($spanAttrs['gen_ai.usage.total_tokens'] ?? 0);
                    $t['cache_read_tokens']  += (int) ($spanAttrs['gen_ai.usage.cache_read_tokens'] ?? $spanAttrs['gen_ai.usage.cache.read_input_tokens'] ?? 0);
                    $t['cache_write_tokens'] += (int) ($spanAttrs['gen_ai.usage.cache_write_tokens'] ?? $spanAttrs['gen_ai.usage.cache.creation_input_tokens'] ?? 0);
                    unset($t);
                }
            }
        }

        foreach ($byDeployment as $bucket) {
            try {
                $this->upsertUsageSnapshot(
                    $bucket['deployment_id'],
                    $bucket['container_name'],
                    $bucket['model'],
                    $bucket['system'],
                    $bucket['tokens'],
                );
                $succeeded++;
            } catch (Throwable $e) {
                $failed++;
                Log::warning('OtlpUsageIngestionService: failed to upsert snapshot — ' . $e->getMessage(), [
                    'deployment_id' => $bucket['deployment_id'],
                    'container_name' => $bucket['container_name'],
                ]);
            }
        }

        if ($failed > 0) {
            Log::warning("OtlpUsageIngestionService: {$failed} deployment(s) failed, {$succeeded} succeeded.");
        }

        return $failed === 0;
    }

    /**
     * Legacy OTLP metrics format: resourceMetrics[].scopeMetrics[].metrics[]
     *
     * @param  array<int, array<string, mixed>> $resourceMetrics
     */
    private function ingestMetrics(array $resourceMetrics): bool
    {
        $succeeded = 0;
        $failed = 0;

        foreach ($resourceMetrics as $rm) {
            try {
                $attrs = $this->extractAttributes($rm['resource']['attributes'] ?? []);
                $tokens = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0, 'cache_read_tokens' => 0, 'cache_write_tokens' => 0];
                $model = $attrs['gen_ai.request.model'] ?? null;
                $system = $attrs['gen_ai.system'] ?? null;

                foreach ($rm['scopeMetrics'] ?? [] as $scope) {
                    foreach ($scope['metrics'] ?? [] as $metric) {
                        $name = $metric['name'] ?? '';
                        $value = $this->extractFirstDataPointValue($metric);

                        match ($name) {
                            'gen_ai.usage.input_tokens'       => $tokens['input_tokens'] += $value,
                            'gen_ai.usage.output_tokens'      => $tokens['output_tokens'] += $value,
                            'gen_ai.usage.total_tokens'       => $tokens['total_tokens'] += $value,
                            'gen_ai.usage.cache_read_tokens'  => $tokens['cache_read_tokens'] += $value,
                            'gen_ai.usage.cache_write_tokens' => $tokens['cache_write_tokens'] += $value,
                            default => null,
                        };

                        if ($model === null || $system === null) {
                            $dp0Attrs = $this->extractAttributes($metric['gauge']['dataPoints'][0]['attributes'] ?? []);
                            $model ??= $dp0Attrs['gen_ai.request.model'] ?? null;
                            $system ??= $dp0Attrs['gen_ai.system'] ?? null;
                        }
                    }
                }

                $this->upsertUsageSnapshot($attrs['agent.deployment_id'] ?? null, $attrs['agent.container_name'] ?? null, $model, $system, $tokens);
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
     * Resolve deployment and upsert AgentUsageSnapshot.
     *
     * @param  array<string, int> $tokens
     */
    private function upsertUsageSnapshot(?string $deploymentId, ?string $containerName, ?string $model, ?string $system, array $tokens): void
    {
        if (empty($deploymentId) && empty($containerName)) {
            return;
        }

        $deployment = $this->resolveDeployment($deploymentId, $containerName);

        if ($deployment === null) {
            Log::debug("OtlpUsageIngestionService: no deployment found for id={$deploymentId} container={$containerName}");

            return;
        }

        // Compute total if not emitted separately
        if ($tokens['total_tokens'] === 0 && ($tokens['input_tokens'] + $tokens['output_tokens']) > 0) {
            $tokens['total_tokens'] = $tokens['input_tokens'] + $tokens['output_tokens'];
        }

        if ($tokens['total_tokens'] === 0) {
            return;
        }

        $provider = $deployment->provider
            ?? ($system ?? null)
            ?? ($model !== null ? BaseCollectDeploymentUsageAction::inferLlmProvider($model) : null);

        $app = Apps::getById($deployment->apps_id);
        $company = Companies::getById($deployment->companies_id);

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
                'total_sessions' => 0,
                'raw_output' => '',
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
