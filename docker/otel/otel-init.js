'use strict';

/**
 * OpenTelemetry bootstrap — required via NODE_OPTIONS=--require /app/otel-init.js
 *
 * Auto-instruments Anthropic / OpenAI LLM calls and exports spans to the
 * OTel Collector over gRPC. The spanmetrics connector in the collector
 * aggregates gen_ai.usage.* attributes into metrics and forwards them to
 * the Kanvas API for storage in agent_usage_snapshots.
 *
 * Resource attributes set here are attached to every span:
 *   agent.deployment_id  — from KANVAS_DEPLOYMENT_ID (always set by the deploy action)
 *   agent.container_name — from HOSTNAME (Docker sets this to the container ID / name)
 *   service.name         — from OTEL_SERVICE_NAME (set to "openclaw" or "hermes")
 */

const { NodeSDK } = require('@opentelemetry/sdk-node');
const { OTLPTraceExporter } = require('@opentelemetry/exporter-trace-otlp-grpc');
const { Resource } = require('@opentelemetry/resources');
const { AnthropicInstrumentation } = require('@traceloop/instrumentation-anthropic');

// Graceful no-op if the collector endpoint is not configured
const collectorEndpoint = process.env.OTEL_EXPORTER_OTLP_ENDPOINT || 'http://otel-collector:4317';

const sdk = new NodeSDK({
  resource: new Resource({
    'service.name': process.env.OTEL_SERVICE_NAME ?? 'agent-runtime',
    'agent.deployment_id': process.env.KANVAS_DEPLOYMENT_ID ?? '',
    'agent.container_name': process.env.HOSTNAME ?? '',
  }),
  traceExporter: new OTLPTraceExporter({
    url: collectorEndpoint,
  }),
  instrumentations: [
    new AnthropicInstrumentation(),
  ],
});

try {
  sdk.start();
} catch (err) {
  // Never crash the agent runtime if OTel fails to start
  console.warn('[otel-init] Failed to start OpenTelemetry SDK:', err?.message ?? err);
}

process.on('SIGTERM', () => {
  sdk.shutdown().catch((err) => {
    console.warn('[otel-init] Error shutting down OpenTelemetry SDK:', err?.message ?? err);
  });
});
