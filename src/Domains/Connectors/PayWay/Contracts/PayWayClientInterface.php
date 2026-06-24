<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay\Contracts;

use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;

interface PayWayClientInterface
{
    public function payUsing3ds(array $payload): PayWayResponse;

    public function completePayment(array $payload): PayWayResponse;

    public function getTransaction(string $numeroCompra): PayWayResponse;

    public function traceTransaction(string $traceId): PayWayResponse;

    public function voidTransaction(string $numeroCompra, string $traceId): PayWayResponse;
}
