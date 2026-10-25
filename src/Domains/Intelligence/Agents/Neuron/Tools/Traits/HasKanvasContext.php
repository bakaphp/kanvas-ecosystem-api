<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Support\CrossCompanyReadLedger;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\ToolProperty;

trait HasKanvasContext
{
    protected Apps $app;
    protected Companies $company;
    protected Users $user;

    /**
     * The companies a READ may span; `$company` stays the own company every write uses.
     */
    protected ?CompanyScope $companyScope = null;

    /**
     * The agent running this turn, when the host knows it.
     *
     * Optional in slot four, so the existing `withContext($app, $company, $user)` call sites keep
     * working. It cannot be recovered from `$user`: an agent's user is routinely shared — one user in
     * production backs 28 agents — so `Agent::fromUser()` returns an arbitrary one of them.
     *
     * NOT named `$agent`: four tools already promote their own `$agent` in the constructor
     * (ScheduleReminderTool, ScheduleAgentTaskTool, CreateEngagementPageTool,
     * ProvisionMyEmailInboxTool) and a trait re-declaring it is a fatal property conflict at class
     * load, not a runtime error.
     */
    protected ?Agent $actingAgent = null;

    public function withContext(
        Apps $app,
        Companies $company,
        Users $user,
        ?Agent $agent = null,
        ?CompanyScope $scope = null,
    ): static {
        $this->app = $app;
        $this->company = $company;
        $this->user = $user;
        $this->actingAgent = $agent;
        $this->companyScope = $scope;

        return $this;
    }

    /**
     * Always a scope, never the bare company, so the AppKey branch in `scopeFromCompany()` cannot
     * widen it. Calling it is the read: a cross-company scope writes its ledger event here, once per
     * turn, so anything that only inspects the scope (building tool properties, labelling) reads
     * `$this->companyScope` directly instead.
     */
    protected function auditedReadScope(): CompanyScope
    {
        $scope = $this->companyScope ?? CompanyScope::ownCompanyOnly($this->app, $this->company);

        if ($scope->shouldAudit()) {
            CrossCompanyReadLedger::record(
                $scope,
                method_exists($this, 'getName') ? $this->getName() : static::class
            );
        }

        return $scope;
    }

    /**
     * Declared only when the scope spans companies, so single-company agents keep their exact schema.
     *
     * @return list<ToolProperty>
     */
    protected function tenantCompanyProperty(): array
    {
        if ($this->companyScope === null || ! $this->companyScope->isCrossCompany()) {
            return [];
        }

        return [
            new ToolProperty(
                name: 'tenant_company_id',
                type: PropertyType::INTEGER,
                description: 'Read only one of your companies instead of all of them. One of: '
                    . $this->companyScope->describeCompanies() . '. Omit to read every company you can see.',
                required: false,
            ),
        ];
    }

    /**
     * For a WRITE resolver that missed in the agent's own company: when the id is real inside the
     * read scope, say so. "Does not exist, create one" would make the model create a copy in the
     * own company and act on that.
     *
     * @param class-string<Model> $modelClass
     *
     * @return array{status: string, message: string}|null
     */
    protected function belongsToAnotherCompanyError(string $modelClass, int $id, string $noun): ?array
    {
        if (! CompanyScope::needsTenantLabel($this->companyScope)) {
            return null;
        }

        /** @var Model|null $record */
        $record = $modelClass::query()
            ->where('id', $id)
            ->fromApp($this->app)
            ->fromCompany($this->companyScope)
            ->notDeleted()
            ->first();

        if ($record === null) {
            return null;
        }

        return [
            'status' => 'error',
            'message' => sprintf(
                '%s %d belongs to %s. This agent only changes %s records: read it if you need to, but do not '
                . 'create a copy here and do not retry this change.',
                ucfirst($noun),
                $id,
                $this->companyScope->companyName((int) $record->getAttribute('companies_id')),
                $this->company->name,
            ),
        ];
    }

    /**
     * An id outside the scope is refused, so a guessed id reads nothing.
     *
     * @return array{status: string, message: string}|null the refusal, or null when the read may proceed
     */
    protected function narrowReadScope(?int $tenantCompanyId): ?array
    {
        if ($tenantCompanyId === null || $tenantCompanyId <= 0) {
            return null;
        }

        $scope = $this->companyScope ?? CompanyScope::ownCompanyOnly($this->app, $this->company);

        if (! $scope->containsCompany($tenantCompanyId)) {
            return [
                'status' => 'error',
                'message' => sprintf(
                    'Company %d is not one you can read. Your companies: %s. Do not retry with that id.',
                    $tenantCompanyId,
                    $scope->describeCompanies(),
                ),
            ];
        }

        $this->companyScope = $scope->narrowToCompany($tenantCompanyId);

        return null;
    }

    /**
     * @return array{tenant_company_id: int, tenant_company: string|null}|array{}
     */
    protected function tenantLabel(Model|int|null $record): array
    {
        if ($this->companyScope === null || $record === null) {
            return [];
        }

        $companyId = $record instanceof Model ? (int) $record->getAttribute('companies_id') : $record;

        return $this->companyScope->tenantLabelFor($companyId);
    }

    /**
     * @return array{scope: array{tenant_companies: list<string>}}|array{}
     */
    protected function tenantScopeSummary(): array
    {
        return $this->companyScope?->tenantScopeSummary() ?? [];
    }

    /**
     * An event name can exist in two companies; grouping by company keeps the ranking per company.
     *
     * @return list<string>
     */
    protected function tenantGroupBy(): array
    {
        return CompanyScope::needsTenantLabel($this->companyScope) ? ['companies_id'] : [];
    }

    /**
     * The agent acting this turn, or null when the host could not name one.
     */
    protected function contextAgent(): ?Agent
    {
        return $this->actingAgent;
    }

    /**
     * Whether the counterparty this turn is a prospect rather than staff. Tools whose payload carries
     * the team's internal notes refuse on it; each family phrases the refusal in its own dialect.
     */
    protected function isCustomerSurface(): bool
    {
        return (bool) $this->contextAgent()?->conversesWithCustomer();
    }

    /**
     * The acting user when one is set. Registry-resolved tools only get withContext() when all three
     * dependencies were available, so a tool that writes attributed records needs the null path.
     */
    protected function contextUser(): ?Users
    {
        return isset($this->user) ? $this->user : null;
    }

    /**
     * Whether this tool knows which tenant it is acting for.
     *
     * The properties are typed and non-nullable, so reading one without context is a fatal rather
     * than a null — every tool has to ask before it touches them. Named here so the check reads as
     * one question instead of a pair of issets repeated at eight call sites.
     */
    protected function hasTenantContext(): bool
    {
        return isset($this->app) && isset($this->company);
    }

    /**
     * The refusal a tool returns when it was wired without a tenant. Ids reaching a tool are
     * LLM-supplied and therefore prompt-injectable, so an unscoped fallback would resolve records
     * belonging to another company — every tool fails closed with the same shape instead, and the
     * miswiring is reported rather than swallowed.
     *
     * @return array{reason: string, message: string}
     */
    protected function tenantContextMissingError(string $subject): array
    {
        report(new ValidationException(
            static::class . ' ran with no tenant context. Register the tool through '
            . 'mergeRegisteredTools()/addToolContext(), or call withContext($app, $company, $user) on it.'
        ));

        return [
            'reason' => 'no_tenant_context',
            'message' => "This tool is not bound to a company right now, so the {$subject} cannot be handled.",
        ];
    }
}
