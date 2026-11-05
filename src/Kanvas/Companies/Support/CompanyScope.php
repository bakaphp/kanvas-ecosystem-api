<?php

declare(strict_types=1);

namespace Kanvas\Companies\Support;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;

/**
 * The single source of truth for which company ids a read may touch.
 *
 * Built once per agent turn by `AgentReadAccessCompanies::scopeFor()`: the agent's own company plus
 * the granted companies the requesting user is a member of. `fromCompany()` and the report services
 * accept it in place of a `Companies` and turn `companyIds` into `=` or `IN`; nothing else decides
 * the predicate. Writes never receive it — they keep the own `Companies`.
 *
 * Always a scope, even with one id, so the AppKey branch in `scopeFromCompany()` can never widen it.
 * `shouldLabelRows()` says when rows need the tenant label, `shouldAudit()` when the turn is
 * ledgered, and `narrowToCompany()` is the model asking for one of the granted companies.
 */
final class CompanyScope
{
    /** @var array<int, string> */
    private array $names = [];

    /** @var array<string, true> */
    private array $audited = [];

    /**
     * @param list<int> $companyIds
     */
    private function __construct(
        public readonly Apps $app,
        public readonly Companies $ownCompany,
        public readonly array $companyIds,
        public readonly ?Users $requestingUser = null,
        public readonly ?int $agentId = null,
        public readonly bool $narrowed = false,
    ) {
    }

    public static function ownCompanyOnly(
        Apps $app,
        Companies $ownCompany,
        ?Users $requestingUser = null,
        ?int $agentId = null
    ): self {
        return new self(
            $app,
            $ownCompany,
            [(int) $ownCompany->getId()],
            $requestingUser,
            $agentId
        );
    }

    /**
     * @param list<int> $companyIds
     */
    public static function withCompanies(
        Apps $app,
        Companies $ownCompany,
        array $companyIds,
        ?Users $requestingUser = null,
        ?int $agentId = null
    ): self {
        $companyIds = array_values(array_unique(array_map('intval', [$ownCompany->getId(), ...$companyIds])));

        return new self(
            $app,
            $ownCompany,
            $companyIds,
            $requestingUser,
            $agentId
        );
    }

    /**
     * One company out of the grant: still labelled and, outside the own company, still audited,
     * because the person asked to look there.
     */
    public function narrowToCompany(int $companyId): self
    {
        if (! $this->containsCompany($companyId)) {
            throw new InvalidArgumentException(sprintf('Company %d is not in this scope.', $companyId));
        }

        return new self(
            $this->app,
            $this->ownCompany,
            [$companyId],
            $this->requestingUser,
            $this->agentId,
            narrowed: true,
        );
    }

    public function isCrossCompany(): bool
    {
        return count($this->companyIds) > 1;
    }

    public function shouldLabelRows(): bool
    {
        return $this->isCrossCompany() || $this->narrowed;
    }

    public function shouldAudit(): bool
    {
        return $this->isCrossCompany() || ($this->narrowed && $this->companyIds[0] !== (int) $this->ownCompany->getId());
    }

    public function containsCompany(int $companyId): bool
    {
        return in_array($companyId, $this->companyIds, true);
    }

    /**
     * @return list<int>
     */
    public static function companyIdsOf(CompanyInterface|self $company): array
    {
        return $company instanceof self ? $company->companyIds : [(int) $company->getId()];
    }

    public static function needsTenantLabel(CompanyInterface|self|null $company): bool
    {
        return $company instanceof self && $company->shouldLabelRows();
    }

    /**
     * @param list<int> $extraCompanyIds folded into the predicate; the global company, usually
     */
    public static function applyToQuery(
        EloquentBuilder|QueryBuilder $query,
        string $column,
        CompanyInterface|self $company,
        array $extraCompanyIds = []
    ): EloquentBuilder|QueryBuilder {
        $ids = array_values(array_unique([...$extraCompanyIds, ...self::companyIdsOf($company)]));

        return count($ids) === 1
            ? $query->where($column, $ids[0])
            : $query->whereIn($column, $ids);
    }

    /**
     * @return array<int, string> id => name, in scope order
     */
    public function companyNames(): array
    {
        if ($this->names === []) {
            $found = Companies::query()
                ->whereIn('id', $this->companyIds)
                ->pluck('name', 'id')
                ->all();

            foreach ($this->companyIds as $id) {
                $this->names[$id] = (string) ($found[$id] ?? $id);
            }
        }

        return $this->names;
    }

    public function companyName(int $companyId): ?string
    {
        return $this->companyNames()[$companyId] ?? null;
    }

    /**
     * "Name (id), Name (id)", for tool descriptions and refusals.
     */
    public function describeCompanies(): string
    {
        $parts = [];

        foreach ($this->companyNames() as $id => $name) {
            $parts[] = sprintf('%s (%d)', $name, $id);
        }

        return implode(', ', $parts);
    }

    /**
     * Never keyed `company`: that word already means the person's employer in Apollo rows.
     *
     * @return array{tenant_company_id: int, tenant_company: string|null}|array{}
     */
    public function tenantLabelFor(int $companyId): array
    {
        if (! $this->shouldLabelRows()) {
            return [];
        }

        return [
            'tenant_company_id' => $companyId,
            'tenant_company' => $this->companyName($companyId),
        ];
    }

    /**
     * @return array{scope: array{tenant_companies: list<string>, narrowed_to?: string}}|array{}
     */
    public function tenantScopeSummary(): array
    {
        if (! $this->shouldLabelRows()) {
            return [];
        }

        $scope = ['tenant_companies' => array_values($this->companyNames())];

        if ($this->narrowed) {
            $scope['narrowed_to'] = (string) $this->companyName($this->companyIds[0]);
        }

        return ['scope' => $scope];
    }

    /**
     * Neuron hands every tool call a shallow clone of the tool; the clones share this object, so a
     * mark here holds for the whole turn.
     */
    public function markAudited(string $key): bool
    {
        if (isset($this->audited[$key])) {
            return false;
        }

        $this->audited[$key] = true;

        return true;
    }
}
