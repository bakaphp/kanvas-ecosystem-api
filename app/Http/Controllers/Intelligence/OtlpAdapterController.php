<?php

declare(strict_types=1);

namespace App\Http\Controllers\Intelligence;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Kanvas\Intelligence\AgentRuntime\Services\OtlpUsageIngestionService;

/**
 * Thin adapter that bridges the OTel Collector (OTLP/HTTP JSON) to the
 * OtlpUsageIngestionService.
 *
 * The collector speaks OTLP — it cannot send a GraphQL query body directly.
 * This controller accepts the raw OTLP metrics JSON, validates the shared
 * internal token, and delegates directly to the ingestion service.
 *
 * This endpoint is intended to be reachable only from within the Docker
 * bridge network (otel-collector → php container). It is not guarded by
 * Laravel auth middleware — the X-Internal-Token header acts as the secret.
 */
class OtlpAdapterController extends Controller
{
    public function handle(Request $request, OtlpUsageIngestionService $ingestionService): JsonResponse
    {
        $expectedToken = config('otel.internal_token', '');

        if ($expectedToken === '' || $request->header('X-Internal-Token') !== $expectedToken) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $payload = $request->json()->all();

        if (empty($payload)) {
            // Return OTLP partial-success with no data — don't error on empty flushes
            return response()->json(['partialSuccess' => new \stdClass()]);
        }

        $ingestionService->ingest($payload);

        // OTLP spec: return 200 with partialSuccess object (empty = full success)
        return response()->json(['partialSuccess' => new \stdClass()]);
    }
}
