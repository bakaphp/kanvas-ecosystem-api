<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Services\AppCompanyToolExecutor;
use Throwable;

/**
 * Optional per-call company_uuid for existing tools. Requires HasKanvasContext and GuardsAdminForTool.
 * Clone before rebinding: other tools and later invocations retain their original company.
 */
trait RunsInExplicitCompany
{
    private bool $explicitCompanyAuthorized = false;

    /** @param Closure(static): array $operation */
    protected function inExplicitCompany(string $uuid, ?Agent $agent, Closure $operation): array
    {
        if (! isset($this->app, $this->user) || $agent === null || $this->requestingUser === null) {
            return $this->companyContextError('An identified app administrator and agent context are required.');
        }

        try {
            return new AppCompanyToolExecutor()->execute(
                $this->app,
                $agent,
                $this->requestingUser,
                $uuid,
                function (Companies $company) use ($operation): array {
                    $tool = clone $this;
                    $tool->company = $company;
                    $tool->explicitCompanyAuthorized = true;

                    return [
                        ...$operation($tool),
                        'company_uuid' => $company->uuid,
                        'company_name' => $company->name,
                    ];
                },
            );
        } catch (AuthorizationException $e) {
            return $this->companyContextError($e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->companyContextError('Could not complete the company operation. Verify its state before retrying.');
        }
    }

    private function companyContextError(string $message): array
    {
        return [
            'success' => false, 'status' => 'error', 'hired' => false, 'updated' => false,
            'message' => $message, 'error' => $message,
        ];
    }
}
