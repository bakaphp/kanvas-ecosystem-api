<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Contracts;

/**
 * Generic HTTP client contract for payment connectors.
 *
 * Connectors implement this so their own service classes can be unit-tested
 * against a deterministic mock (e.g. EchoPay's MockClient) without hitting the
 * real provider. The interface is intentionally low-level — it speaks plain
 * HTTP verbs and decoded array bodies, not payment-domain concepts.
 */
interface HttpClientInterface
{
    public function get(string $endpoint): array;

    public function post(string $endpoint, array $data, ?int $timeout = null): array;

    public function patch(string $endpoint, array $data): array;

    public function delete(string $endpoint, array $data = []): array;
}
