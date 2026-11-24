<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Support;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Connectors\Intras\Enums\SipgoRecordKindEnum;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * What a person pasted — a SIPGO link, a SIPGO code, or a bare number — turned into "which kind of
 * record, which legacy id", and from there into the Kanvas row that carries that id.
 *
 * The id in a SIPGO URL is its primary key and the visible code is a prefix on that same id, so a
 * link or a code names the kind outright and nothing is guessed. A bare number is the one ambiguous
 * input: SIPGO ids overlap with Kanvas ids (SIPGO version 7160 is Kanvas version 8329), so it is read
 * as a SIPGO version id and `assumedKind` says so, for the tool to tell the person.
 */
final readonly class SipgoReference
{
    // Anchored on the first path segment so `/manager/companies/12` (a Gestor page) is not a company.
    // The segment and prefix are matched loosely here and validated by the enum, which owns both lists.
    private const string URL_PATTERN = '~^/?([a-z-]+)/(\d+)(?:[/?#]|$)~i';
    private const string HOST_PATTERN = '~^(?:[a-z]+://)?[^/\s]+\.[a-z]{2,}(?=/)~i';
    private const string CODE_PATTERN = '~^([a-z]{2})\s*-?\s*(\d+)$~i';

    public function __construct(
        public SipgoRecordKindEnum $kind,
        public int $legacyId,
        public bool $assumedKind = false,
    ) {
    }

    public static function parse(string $input): ?self
    {
        $input = trim($input);

        $path = (string) preg_replace(self::HOST_PATTERN, '', $input);

        if (preg_match(self::URL_PATTERN, $path, $m) === 1) {
            return self::of(SipgoRecordKindEnum::fromUrlSegment($m[1]), (int) $m[2]);
        }

        if (preg_match(self::CODE_PATTERN, $input, $m) === 1) {
            return self::of(SipgoRecordKindEnum::fromCodePrefix($m[1]), (int) $m[2]);
        }

        if (preg_match('~^\d+$~', $input) === 1) {
            return self::of(SipgoRecordKindEnum::VERSION, (int) $input, assumedKind: true);
        }

        return null;
    }

    public static function codeFor(SipgoRecordKindEnum $kind, int $legacyId): string
    {
        $prefix = $kind->codePrefix();

        return $prefix === null ? $kind->label() . ' ' . $legacyId : $prefix . $legacyId;
    }

    /**
     * The code SIPGO shows for a Kanvas row, or null when the row was never linked to SIPGO.
     */
    public static function codeOf(Model $entity, SipgoRecordKindEnum $kind): ?string
    {
        $legacyId = (int) $entity->get($kind->linkField()->value);

        return $legacyId > 0 ? self::codeFor($kind, $legacyId) : null;
    }

    public function code(): string
    {
        return self::codeFor($this->kind, $this->legacyId);
    }

    /**
     * The SIPGO host is a per-app setting (`ConfigurationEnum::INTRAS_BASE_URL`), never a constant: this
     * repository is public and the tenant's ERP address is not.
     */
    public function url(string $baseUrl): string
    {
        return sprintf('%s/%s/%d/edit', rtrim($baseUrl, '/'), $this->kind->urlSegment(), $this->legacyId);
    }

    /**
     * The Kanvas row linked to this SIPGO id inside the given tenant scope, or null.
     *
     * The custom-field lookup filters on `companies_id` first because that is what the only composite
     * index on (model, name, value) leads with: without it the planner walks the `name` index, 22K rows
     * for INTRAS_EVENT_VERSION_ID on a 3M-row table, against 4 with it. But that column records the
     * writer's context, not the owner (a People row can carry fields tagged with another company when
     * the import ran under it), so a miss retries without it. The tenant check that matters is the one
     * on the model's own table, in both branches.
     */
    public function resolve(Apps $app, CompanyScope|CompanyInterface $scope): ?Model
    {
        $companyIds = CompanyScope::companyIdsOf($scope);

        $entityIds = $this->linkedEntityIds($companyIds) ?: $this->linkedEntityIds(null);

        if ($entityIds === []) {
            return null;
        }

        return $this->kind->modelClass()::query()
            ->whereIn('id', $entityIds)
            ->fromApp($app)
            ->fromCompany($scope)
            ->notDeleted()
            ->first();
    }

    /**
     * @param list<int>|null $companyIds
     *
     * @return list<int>
     */
    private function linkedEntityIds(?array $companyIds): array
    {
        $modelClass = $this->kind->modelClass();

        return AppsCustomFields::query()
            ->when($companyIds !== null, fn ($query) => $query->whereIn('companies_id', $companyIds))
            ->whereIn('model_name', array_unique([$modelClass, SystemModules::getLegacyNamespace($modelClass)]))
            ->where('name', $this->kind->linkField()->value)
            ->where('value', (string) $this->legacyId)
            ->where('is_deleted', 0)
            ->pluck('entity_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private static function of(?SipgoRecordKindEnum $kind, int $legacyId, bool $assumedKind = false): ?self
    {
        if ($kind === null || $legacyId <= 0) {
            return null;
        }

        return new self($kind, $legacyId, $assumedKind);
    }
}
