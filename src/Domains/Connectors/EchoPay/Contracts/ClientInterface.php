<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Contracts;

interface ClientInterface
{
    public function get(string $endpoint): array;

    public function post(string $endpoint, array $data, ?int $timeout = null): array;

    public function patch(string $endpoint, array $data): array;

    public function delete(string $endpoint, array $data = []): array;
}
