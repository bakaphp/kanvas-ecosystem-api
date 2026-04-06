<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Processors;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;

class ProcessorFactory
{
    /**
     * Resolve a payment processor by its name.
     * Processors are registered in the service container as "payment_processor.{name}".
     *
     * @throws \InvalidArgumentException when no processor is registered for the given name
     */
    public static function make(string $processorName, Apps $app, Companies $company): PaymentProcessorInterface
    {
        $binding = "payment_processor.{$processorName}";

        if (! app()->bound($binding)) {
            throw new \InvalidArgumentException("No payment processor registered for '{$processorName}'.");
        }

        return app($binding, ['app' => $app, 'company' => $company]);
    }
}
