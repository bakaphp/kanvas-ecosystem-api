<?php

declare(strict_types=1);

namespace Kanvas\Souk\Insurance\Processors;

use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Insurance\Contracts\InsuranceProcessorInterface;

class InsuranceProcessorFactory
{
    /**
     * Resolve an insurance processor by its provider name.
     * Processors are registered in the service container as "insurance_processor.{provider}"
     * (see App\Providers\InsuranceProcessorServiceProvider).
     *
     * @throws InvalidArgumentException when no processor is registered for the given provider
     */
    public static function make(string $provider, Apps $app, Companies $company): InsuranceProcessorInterface
    {
        $binding = "insurance_processor.{$provider}";

        if (! app()->bound($binding)) {
            throw new InvalidArgumentException("No insurance processor registered for '{$provider}'.");
        }

        return app($binding, ['app' => $app, 'company' => $company]);
    }
}
