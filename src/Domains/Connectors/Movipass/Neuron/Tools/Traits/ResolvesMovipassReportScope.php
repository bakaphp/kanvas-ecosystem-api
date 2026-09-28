<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools\Traits;

use DateTimeZone;
use Illuminate\Support\Facades\Log;
use Kanvas\Souk\Orders\Services\OrderProviderScopeService;
use Throwable;

/**
 * Window + tenant scoping shared by the Movipass report tools. Requires HasKanvasContext.
 */
trait ResolvesMovipassReportScope
{
    /**
     * Day boundaries decide every daily figure, so the window has to be cut in the tenant's zone —
     * bucketing in UTC files four hours of Dominican evening traffic under the wrong day and makes
     * the agent contradict the dashboard. Tenant configs carry IANA-shaped-but-invalid strings
     * often enough to throw inside Carbon, so each candidate is proven before it is used.
     */
    protected function reportTimezone(?string $timezone): string
    {
        $candidates = [
            'argument' => $timezone,
            'company_field' => $this->company->get('timezone'),
            'company_column' => $this->company->timezone,
            'app' => $this->app->get('timezone'),
        ];

        foreach ($candidates as $source => $candidate) {
            if (! is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $candidate = trim($candidate);

            try {
                new DateTimeZone($candidate);

                return $candidate;
            } catch (Throwable) {
                Log::warning('Invalid timezone for Movipass report tool; falling back', [
                    'source' => $source,
                    'company_id' => $this->company->getId(),
                    'invalid_value' => $candidate,
                ]);
            }
        }

        return 'UTC';
    }

    /**
     * Agents never get the app-owner bypass the GraphQL resolver grants a human: an aggregate is
     * too easy to walk across tenants, and an agent whose user happens to own the app would
     * otherwise read every provider's revenue from inside one provider's chat.
     *
     * Returns null when the app is not running the provider model. The Souk stats actions these
     * reports delegate to are app-scoped and take no company, so the provider pivot is the only
     * thing bounding them to one tenant — without it the tool would quietly report the whole app.
     *
     * @return array<int, int>|null
     */
    protected function providerCompanyScope(?int $providerCompanyId): ?array
    {
        if ($this->app->get('B2B_MAIN_COMPANY_ID') === null) {
            return null;
        }

        return OrderProviderScopeService::resolve(
            app: $this->app,
            company: $this->company,
            requested: $providerCompanyId !== null ? [$providerCompanyId] : [],
        );
    }

    /**
     * @return array<string, string>
     */
    protected function providerScopeUnavailable(): array
    {
        return [
            'status' => 'error',
            'message' => 'This app is not set up for the Movipass provider model (B2B_MAIN_COMPANY_ID is unset), '
                . 'so this report cannot be bounded to a single company. Use the generic order report tools instead.',
        ];
    }

    /**
     * Neuron's ToolProperty can't emit a JSON-schema `items` for an ARRAY param, so list inputs
     * travel as comma-separated scalars.
     *
     * @return list<string>|null
     */
    protected function parseListParam(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $value)),
            fn (string $part): bool => $part !== '',
        ));

        return $parts === [] ? null : $parts;
    }
}
