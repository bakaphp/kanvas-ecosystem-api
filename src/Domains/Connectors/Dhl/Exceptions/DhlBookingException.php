<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Dhl\Exceptions;

use Exception;

class DhlBookingException extends Exception
{
    public static function missingConsigneeData(array $fields): self
    {
        return new self('Cannot book DHL, the order is missing: ' . implode(', ', $fields));
    }

    public static function quoteChanged(string $detail): self
    {
        return new self("The applied DHL quote no longer matches the company configuration: {$detail}");
    }

    public static function pendingWithoutTracking(): self
    {
        return new self('A DHL booking is pending without a tracking number; it will not be retried automatically to avoid a double charge');
    }

    public static function rejected(string $detail): self
    {
        return new self("DHL rejected the shipment: {$detail}");
    }
}
