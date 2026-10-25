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
 * Built once per agent turn by `AgentReadAccessCompanies::scopeFor()`: the agent's `home` company
 * plus the granted companies the requesting human is a member of. `fromCompany()` and the report
 * services accept it in place of a `Companies` and turn `ids` into `=` or `IN`; nothing else decides
 * the predicate. Writes never receive it — they keep the home `Companies`.
 *
 * Always a scope, even with one id, so the AppKey branch in `scopeFromCompany()` can never widen it.
 * `labelsRows()` says when rows need the tenant label, `audits()` when the turn is ledgered, and
 * `narrowTo()` is the model asking for one of the granted companies.
 */
final class CompanyScope
{
    /** @var array<int, string> */
    private array $names = [];

    /** @var array<string, true> */
    private array $audited = [];

    /**
     * @param list<int> $ids
     */
    private function __construct(
        public readonly Apps $app,
        public readonly Companies $home,
        public readonly array $ids,
        public readonly ?Users $human = null,
        public readonly ?int $agentId = null,
        public readonly bool $narrowed = false,
    ) {
    }

    /**
     * One company out of the grant: still labelled and, off home, still audited, because the person
     * asked to look there.
     */
    public function narrowTo(int $companyId): self
    {
        if (! $this->contains($companyId)) {
            throw new InvalidArgumentException(sprintf('Company %d is not in this scope.', $companyId));
        }

        return new self(
            $this->app,
            $this->home,
            [$companyId],
            $this->human,
            $this->agentId,
            narrowed: true,
        );
    }

    public function labelsRows(): bool
    {
        return $this->isCrossCompany() || $this->narrowed;
    }

    public function audits(): bool
    {
        return $this->isCrossCompany() || ($this->narrowed && $this->ids[0] !== (int) $this->home->getId());
    }

    /**
     * "Name (id), Name (id)", for tool descriptions and refusals.
     */
    public function menu(): string
    {
        $parts = [];

        foreach ($this->names() as $id => $name) {
            $parts[] = sprintf('%s (%d)', $name, $id);
        }

        return implode(', ', $parts);
    }

    public static function homeOnly(
        Apps $app,
        Companies $home,
        ?Users $human = null,
        ?int $agentId = null
    ): self {
        return new self(
            $app,
            $home,
            [(int) $home->getId()],
            $human,
            $agentId
        );
    }

    /**
     * @param list<int> $ids
     */
    public static function of(
        Apps $app,
        Companies $home,
        array $ids,
        ?Users $human = null,
        ?int $agentId = null
    ): self {
        $ids = array_values(array_unique(array_map('intval', [(int) $home->getId(), ...$ids])));

        return new self(
            $app,
            $home,
            $ids,
            $human,
            $agentId
        );
    }

    public function isCrossCompany(): bool
    {
        return count($this->ids) > 1;
    }

    /**
     * @return list<int>
     */
    public static function idsOf(CompanyInterface|self $company): array
    {
        return $company instanceof self ? $company->ids : [(int) $company->getId()];
    }

    public static function spans(CompanyInterface|self|null $company): bool
    {
        return $company instanceof self && $company->labelsRows();
    }

    /**
     * @param list<int> $alsoIds folded into the predicate; the global company, usually
     */
    public static function constrain(
        EloquentBuilder|QueryBuilder $query,
        string $column,
        CompanyInterface|self $company,
        array $alsoIds = []
    ): EloquentBuilder|QueryBuilder {
        $ids = array_values(array_unique([...$alsoIds, ...self::idsOf($company)]));

        return count($ids) === 1
            ? $query->where($column, $ids[0])
            : $query->whereIn($column, $ids);
    }

    public function contains(int $companyId): bool
    {
        return in_array($companyId, $this->ids, true);
    }

    /**
     * @return array<int, string> id => name, in scope order
     */
    public function names(): array
    {
        if ($this->names === []) {
            $found = Companies::query()
                ->whereIn('id', $this->ids)
                ->pluck('name', 'id')
                ->all();

            foreach ($this->ids as $id) {
                $this->names[$id] = (string) ($found[$id] ?? $id);
            }
        }

        return $this->names;
    }

    public function nameOf(int $companyId): ?string
    {
        return $this->names()[$companyId] ?? null;
    }

    /**
     * Never keyed `company`: that word already means the person's employer in Apollo rows.
     *
     * @return array{tenant_company_id: int, tenant_company: string|null}|array{}
     */
    public function tenantLabel(int $companyId): array
    {
        if (! $this->labelsRows()) {
            return [];
        }

        return [
            'tenant_company_id' => $companyId,
            'tenant_company' => $this->nameOf($companyId),
        ];
    }

    /**
     * @return array{scope: array{tenant_companies: list<string>, narrowed_to?: string}}|array{}
     */
    public function summary(): array
    {
        if (! $this->labelsRows()) {
            return [];
        }

        $scope = ['tenant_companies' => array_values($this->names())];

        if ($this->narrowed) {
            $scope['narrowed_to'] = (string) $this->nameOf($this->ids[0]);
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
