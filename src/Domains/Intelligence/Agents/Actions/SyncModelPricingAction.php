<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

use function Sentry\captureException;

class SyncModelPricingAction
{
    /**
     * Primary: LiteLLM's community-maintained JSON catalogue.
     * Updates within hours of new model launches, covers ~600 entries.
     */
    public const LITELLM_URL = 'https://raw.githubusercontent.com/BerriAI/litellm/main/model_prices_and_context_window.json';

    /**
     * Fallback: OpenRouter's normalized models endpoint.
     */
    public const OPENROUTER_URL = 'https://openrouter.ai/api/v1/models';

    /**
     * @param  string|null  $upstreamJson  Optional pre-fetched JSON (for tests / dry-run)
     * @return array{
     *   inserted: int,
     *   versioned: int,
     *   unchanged: int,
     *   skipped: int,
     *   source: string,
     * }
     */
    public function execute(?string $upstreamJson = null): array
    {
        $upstream = $upstreamJson !== null
            ? ['json' => $upstreamJson, 'source' => 'injected']
            : $this->fetchUpstream();

        $rows = $this->parseLiteLLM($upstream['json']);
        if ($rows === []) {
            throw new RuntimeException('Upstream pricing payload contained zero usable rows');
        }

        $today = Carbon::now()->toDateString();
        $yesterday = Carbon::now()->subDay()->toDateString();
        $inserted = 0;
        $versioned = 0;
        $unchanged = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $current = DB::connection('intelligence')
                ->table('model_pricing')
                ->where('provider', $row['provider'])
                ->where('model', $row['model'])
                ->whereNull('effective_until')
                ->where('is_deleted', 0)
                ->orderByDesc('effective_from')
                ->first();

            if ($current === null) {
                $this->insertRow($row, $today, $upstream['source']);
                $inserted++;

                continue;
            }

            if ($this->rateMatches($current, $row)) {
                $unchanged++;

                continue;
            }

            // Versioned change — close the prior row, insert the new one.
            DB::connection('intelligence')->table('model_pricing')
                ->where('id', $current->id)
                ->update(['effective_until' => $yesterday, 'updated_at' => Carbon::now()]);

            $this->insertRow($row, $today, $upstream['source']);
            $versioned++;
        }

        return [
            'inserted' => $inserted,
            'versioned' => $versioned,
            'unchanged' => $unchanged,
            'skipped' => $skipped,
            'source' => $upstream['source'],
        ];
    }

    /**
     * @return array{json: string, source: string}
     */
    private function fetchUpstream(): array
    {
        try {
            $resp = Http::timeout(30)->get(self::LITELLM_URL);
            if ($resp->ok() && $resp->body() !== '') {
                return ['json' => $resp->body(), 'source' => 'litellm'];
            }
        } catch (Throwable $e) {
            captureException($e);
            Log::warning('model-pricing-sync: LiteLLM fetch failed', ['error' => $e->getMessage()]);
        }

        try {
            $resp = Http::timeout(30)->get(self::OPENROUTER_URL);
            if ($resp->ok() && $resp->body() !== '') {
                return ['json' => $this->normalizeOpenRouter($resp->body()), 'source' => 'openrouter'];
            }
        } catch (Throwable $e) {
            captureException($e);
            Log::warning('model-pricing-sync: OpenRouter fetch failed', ['error' => $e->getMessage()]);
        }

        throw new RuntimeException('Both upstream pricing sources unavailable');
    }

    /**
     * @return array<int, array{
     *   provider: string,
     *   model: string,
     *   input_per_million: float,
     *   output_per_million: float,
     *   cache_read_per_million: ?float,
     *   cache_write_per_million: ?float,
     * }>
     */
    private function parseLiteLLM(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Pricing payload did not decode to an array');
        }

        $out = [];
        foreach ($decoded as $key => $entry) {
            if (! is_array($entry) || ! isset($entry['litellm_provider'])) {
                continue;
            }
            if (! isset($entry['input_cost_per_token']) || ! isset($entry['output_cost_per_token'])) {
                continue;
            }

            $provider = (string) $entry['litellm_provider'];
            $model = $this->stripVendorPrefix((string) $key, $provider);

            $out[] = [
                'provider' => $provider,
                'model' => $model,
                'input_per_million' => (float) $entry['input_cost_per_token'] * 1_000_000,
                'output_per_million' => (float) $entry['output_cost_per_token'] * 1_000_000,
                'cache_read_per_million' => isset($entry['cache_read_input_token_cost'])
                    ? (float) $entry['cache_read_input_token_cost'] * 1_000_000
                    : null,
                'cache_write_per_million' => isset($entry['cache_creation_input_token_cost'])
                    ? (float) $entry['cache_creation_input_token_cost'] * 1_000_000
                    : null,
            ];
        }

        return $out;
    }

    /**
     * Map OpenRouter's `{ data: [{ id, pricing: { prompt, completion } }] }`
     * into LiteLLM's shape so the same parser handles both.
     */
    private function normalizeOpenRouter(string $json): string
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! is_array($decoded['data'] ?? null)) {
            return '{}';
        }

        $out = [];
        foreach ($decoded['data'] as $entry) {
            if (! is_array($entry) || ! is_array($entry['pricing'] ?? null)) {
                continue;
            }
            $id = (string) ($entry['id'] ?? '');
            if ($id === '' || ! str_contains($id, '/')) {
                continue;
            }
            [$provider, $model] = explode('/', $id, 2);

            $out[$id] = [
                'litellm_provider' => $provider,
                'input_cost_per_token' => (float) ($entry['pricing']['prompt'] ?? 0),
                'output_cost_per_token' => (float) ($entry['pricing']['completion'] ?? 0),
            ];
        }

        return (string) json_encode($out);
    }

    private function stripVendorPrefix(string $key, string $provider): string
    {
        $prefix = $provider . '/';
        if (str_starts_with($key, $prefix)) {
            return substr($key, strlen($prefix));
        }

        return $key;
    }

    /**
     * @param array{
     *   provider: string,
     *   model: string,
     *   input_per_million: float,
     *   output_per_million: float,
     *   cache_read_per_million: ?float,
     *   cache_write_per_million: ?float,
     * } $row
     */
    private function insertRow(array $row, string $effectiveFrom, string $source): void
    {
        DB::connection('intelligence')->table('model_pricing')->insert([
            'provider' => $row['provider'],
            'model' => $row['model'],
            'input_per_million' => $row['input_per_million'],
            'output_per_million' => $row['output_per_million'],
            'cache_read_per_million' => $row['cache_read_per_million'],
            'cache_write_per_million' => $row['cache_write_per_million'],
            'effective_from' => $effectiveFrom,
            'effective_until' => null,
            'source' => $source,
            'is_deleted' => 0,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * @param object $current Row from `model_pricing`
     * @param array{
     *   input_per_million: float,
     *   output_per_million: float,
     *   cache_read_per_million: ?float,
     *   cache_write_per_million: ?float,
     * } $upstream
     */
    private function rateMatches(object $current, array $upstream): bool
    {
        // DECIMAL columns come back as strings; cast on both sides + epsilon
        // tolerance because float reps of fractional cents drift in the 6th place.
        $eps = 0.000001;
        if (abs((float) $current->input_per_million - $upstream['input_per_million']) > $eps) {
            return false;
        }
        if (abs((float) $current->output_per_million - $upstream['output_per_million']) > $eps) {
            return false;
        }
        if ($this->nullableDiffers($current->cache_read_per_million, $upstream['cache_read_per_million'], $eps)) {
            return false;
        }
        if ($this->nullableDiffers($current->cache_write_per_million, $upstream['cache_write_per_million'], $eps)) {
            return false;
        }

        return true;
    }

    private function nullableDiffers(mixed $a, ?float $b, float $eps): bool
    {
        if ($a === null && $b === null) {
            return false;
        }
        if ($a === null || $b === null) {
            return true;
        }

        return abs((float) $a - $b) > $eps;
    }
}
