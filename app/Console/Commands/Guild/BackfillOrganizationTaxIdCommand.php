<?php

declare(strict_types=1);

namespace App\Console\Commands\Guild;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\SystemModules\Models\SystemModules;

/**
 * One-time copy of the tax id custom fields into `organizations.tax_id`. Idempotent: only rows whose
 * column is still null are written. The custom fields stay — other integrations may still read them.
 */
class BackfillOrganizationTaxIdCommand extends Command
{
    use KanvasJobsTrait;

    /** Precedence when an organization carries more than one. */
    public const array FIELDS = ['tax_id', 'rnc', 'ein'];

    private const array KEY_COUNTRY = ['rnc' => 'DO', 'ein' => 'US'];

    protected $signature = 'guild:backfill-organization-tax-id
                            {--app= : Only this app id}
                            {--company= : Only this company id}
                            {--chunk=1000 : Custom field rows per chunk}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Copy the tax_id / rnc / ein organization custom fields into organizations.tax_id';

    private int $filled = 0;
    private int $alreadySet = 0;
    private int $placeholders = 0;
    private int $conflicts = 0;
    private int $customFieldRows = 0;
    private int $typeUnresolved = 0;

    /** @var array<int, string|null> */
    private array $companyCountries = [];

    /** @var array<int, true> organizations already decided, so a dry run doesn't count one twice */
    private array $seen = [];

    /** @var array<int, Apps> */
    private array $apps = [];

    public function handle(): int
    {
        // Artisan keeps one instance per process, so a second call (a test, a scheduler) starts clean.
        $this->filled = $this->alreadySet = $this->placeholders = $this->conflicts = $this->customFieldRows = $this->typeUnresolved = 0;
        $this->seen = [];

        $dryRun = (bool) $this->option('dry-run');
        $appId = $this->option('app') !== null ? (int) $this->option('app') : null;
        $companyId = $this->option('company') !== null ? (int) $this->option('company') : null;

        $query = AppsCustomFields::query()
            ->whereIn('model_name', [Organization::class, SystemModules::getLegacyNamespace(Organization::class)])
            ->whereIn('name', self::FIELDS)
            ->where('is_deleted', 0);

        if ($companyId !== null) {
            $query->where('companies_id', $companyId);
        }

        $query->select(['id', 'entity_id'])->chunkById(
            (int) $this->option('chunk'),
            function (Collection $rows) use ($appId, $companyId, $dryRun): void {
                $this->customFieldRows += $rows->count();
                $this->processEntities(
                    $rows->pluck('entity_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
                    $appId,
                    $companyId,
                    $dryRun
                );
            }
        );

        $this->info(sprintf(
            '%sfilled: %d (type left null, ambiguous: %d), already set: %d, placeholder/blank skipped: %d, conflicts: %d, tax custom field rows seen: %d',
            $dryRun ? '[dry-run] ' : '',
            $this->filled,
            $this->typeUnresolved,
            $this->alreadySet,
            $this->placeholders,
            $this->conflicts,
            $this->customFieldRows,
        ));

        return self::SUCCESS;
    }

    /**
     * An entity's keys can straddle a chunk boundary, so every key of every entity in the chunk is re-read
     * here; a later visit to the same organization is skipped.
     *
     * @param list<int> $entityIds
     */
    private function processEntities(
        array $entityIds,
        ?int $appId,
        ?int $companyId,
        bool $dryRun
    ): void {
        $organizations = Organization::query()
            ->whereIn('id', $entityIds)
            ->when($appId !== null, fn ($q) => $q->where('apps_id', $appId))
            ->when($companyId !== null, fn ($q) => $q->where('companies_id', $companyId))
            ->get(['id', 'apps_id', 'companies_id', 'tax_id']);

        if ($organizations->isEmpty()) {
            return;
        }

        $valuesByEntity = AppsCustomFields::query()
            ->whereIn('model_name', [Organization::class, SystemModules::getLegacyNamespace(Organization::class)])
            ->whereIn('name', self::FIELDS)
            ->whereIn('entity_id', $organizations->pluck('id')->all())
            ->where('is_deleted', 0)
            ->get(['entity_id', 'name', 'value'])
            ->groupBy('entity_id');

        foreach ($organizations->groupBy('apps_id') as $orgAppId => $appOrganizations) {
            $app = $this->apps[(int) $orgAppId] ??= Apps::query()->find((int) $orgAppId);
            if ($app !== null) {
                $this->overwriteAppService($app);
            }

            foreach ($appOrganizations as $organization) {
                $this->processOrganization($organization, $valuesByEntity->get($organization->id, collect()), $dryRun);
            }
        }
    }

    private function processOrganization(Organization $organization, Collection $fields, bool $dryRun): void
    {
        if (isset($this->seen[$organization->id])) {
            return;
        }
        $this->seen[$organization->id] = true;

        if ($organization->tax_id !== null) {
            $this->alreadySet++;

            return;
        }

        $values = [];
        foreach (self::FIELDS as $name) {
            $raw = $fields->firstWhere('name', $name)?->value;
            $normalized = is_scalar($raw) ? Str::normalizeTaxId((string) $raw) : null;
            if ($normalized !== null) {
                $values[$name] = $normalized;
            }
        }

        if ($values === []) {
            $this->placeholders++;

            return;
        }

        if (count(array_unique($values)) > 1) {
            $this->conflicts++;
            $this->warn(sprintf('organization %d: conflicting tax ids %s, using %s', $organization->id, json_encode($values), reset($values)));
        }

        $taxId = reset($values);
        // The key names its country (an `ein` on a Dominican company is a US vendor's); only the generic
        // `tax_id` key falls back to the company's.
        $country = self::KEY_COUNTRY[array_key_first($values)] ?? $this->countryOf((int) $organization->companies_id);
        $type = TaxIdTypeEnum::infer($country, $taxId);

        $this->filled++;
        if ($type === null) {
            $this->typeUnresolved++;
        }

        if ($dryRun) {
            return;
        }

        // A query update, not save(): the column is new, so firing the update workflows and the
        // duplicate check for 30k+ rows would announce an edit nobody made.
        Organization::query()
            ->whereKey($organization->id)
            ->whereNull('tax_id')
            ->update(['tax_id' => $taxId, 'tax_id_type' => $type?->value]);
    }

    private function countryOf(int $companyId): ?string
    {
        if (! array_key_exists($companyId, $this->companyCountries)) {
            $this->companyCountries[$companyId] = Companies::query()->find($companyId)?->countryCode();
        }

        return $this->companyCountries[$companyId];
    }
}
