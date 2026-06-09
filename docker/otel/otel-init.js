'use strict';

/**
 * OpenTelemetry bootstrap — required via NODE_OPTIONS=--require /opt/otel/init.js
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
 *
 * Packages are installed at /opt/otel/node_modules/ during the image build
 * (see the {{OTEL_LAYER}} section in the Dockerfile template).
 * Absolute requires keep this script isolated from the app's own node_modules.
 */

// Bail out silently if the collector endpoint is not configured — this prevents
// the script from crashing agents that were deployed before OTel was enabled.
if (!process.env.OTEL_EXPORTER_OTLP_ENDPOINT) {
  return;
}

try {
  const { NodeSDK } = require('/opt/otel/node_modules/@opentelemetry/sdk-node');
  const { OTLPTraceExporter } = require('/opt/otel/node_modules/@opentelemetry/exporter-trace-otlp-grpc');
  const { Resource } = require('/opt/otel/node_modules/@opentelemetry/resources');
  const { AnthropicInstrumentation } = require('/opt/otel/node_modules/@traceloop/instrumentation-anthropic');

  const sdk = new NodeSDK({
    resource: new Resource({
      'service.name': process.env.OTEL_SERVICE_NAME || 'agent-runtime',
      'agent.deployment_id': process.env.KANVAS_DEPLOYMENT_ID || '',
      'agent.container_name': process.env.HOSTNAME || '',
    }),
    traceExporter: new OTLPTraceExporter({
      url: process.env.OTEL_EXPORTER_OTLP_ENDPOINT,
    }),
    instrumentations: [
      new AnthropicInstrumentation(),
    ],
  });

  sdk.start();

  process.on('SIGTERM', () => {
    sdk.shutdown().catch(() => {});
  });
} catch (err) {
  // Never crash the agent runtime if OTel fails to start
  process.stderr.write('[otel-init] Failed to start: ' + (err && err.message ? err.message : String(err)) + '\n');
}
