<?php

declare(strict_types=1);

namespace App\Console\Commands\Intelligence\Leads;

use Baka\Support\Str;
use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadVariantInterest;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\SystemModules\Models\SystemModules;

final class BackfillLeadVariantInterestsCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:leads:index-variant-interests
                            {--app= : App ID}
                            {--company= : Company ID}
                            {--from= : Only leads created on or after this date}
                            {--dry-run : Resolve and report without writing}
                            {--force : Update an existing matching interest}';

    protected $description = 'Migrate lead variant-interest custom fields into lead_variant_interests and reindex matching leads.';

    public function handle(): int
    {
        $appId = (int) $this->option('app');
        $companyId = (int) $this->option('company');
        if ($appId < 1 || $companyId < 1) {
            $this->error('--app and --company are required positive IDs.');

            return self::FAILURE;
        }

        $app = Apps::getById($appId);
        $company = Companies::getById($companyId);
        $this->overwriteAppService($app);

        $variants = $this->variantLookup($appId, $companyId);
        $rows = $this->customFieldQuery($appId, $companyId)->get();
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $seenLeads = [];
        $leadIds = [];
        $stats = [
            'custom_fields' => $rows->count(),
            'matched' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'invalid' => 0,
        ];

        foreach ($rows as $row) {
            $leadId = (int) $row->entity_id;
            if (isset($seenLeads[$leadId])) {
                continue;
            }
            $seenLeads[$leadId] = true;

            $value = Str::jsonToArray($row->value);
            if (! is_array($value) || $value === []) {
                $stats['invalid']++;

                continue;
            }

            [$variant, $matchedBy, $ambiguous] = $this->resolveVariant($value, $variants);
            if ($ambiguous) {
                $stats['ambiguous']++;

                continue;
            }
            if (! $variant instanceof Variants) {
                $stats['unmatched']++;

                continue;
            }

            $stats['matched']++;
            $leadIds[] = $leadId;
            if ($dryRun) {
                continue;
            }

            $attributes = [
                'apps_id' => $appId,
                'companies_id' => $companyId,
                'leads_id' => $leadId,
                'variants_id' => $variant->getId(),
                'interest_type' => ($value['isPrimary'] ?? true) ? 'primary' : 'secondary',
            ];
            $existing = LeadVariantInterest::query()->where($attributes)->first();
            if ($existing !== null && ! $force) {
                $stats['unchanged']++;

                continue;
            }

            $interest = $existing ?? new LeadVariantInterest($attributes);
            $interest->fill([
                ...$attributes,
                'users_id' => (int) $row->users_id,
                'price_at_interest' => $this->price($value),
                'is_active' => true,
                'metadata' => [
                    'source' => LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value,
                    'matched_by' => $matchedBy,
                ],
                'is_deleted' => false,
            ]);
            LeadVariantInterest::withoutEvents(fn (): bool => $interest->saveOrFail());
            $stats[$existing === null ? 'created' : 'updated']++;
        }

        if (! $dryRun && $leadIds !== []) {
            $this->reindexLeads($app, $company, array_values(array_unique($leadIds)));
        }

        $this->table(['Metric', 'Total'], collect($stats)->map(fn (int $total, string $metric): array => [$metric, $total]));

        return self::SUCCESS;
    }

    /**
     * @return array{sku: array<string, list<Variants>>, name: array<string, list<Variants>>}
     */
    private function variantLookup(int $appId, int $companyId): array
    {
        $lookup = ['sku' => [], 'name' => []];

        Variants::query()
            ->where('apps_id', $appId)
            ->where('companies_id', $companyId)
            ->where('is_deleted', false)
            ->where('is_published', true)
            ->whereHas('product', fn ($query) => $query->where('is_deleted', false)->where('is_published', true))
            ->with('product')
            ->get()
            ->each(function (Variants $variant) use (&$lookup): void {
                foreach ([$variant->sku, $variant->ean, $variant->barcode] as $identifier) {
                    $key = $this->normalize((string) $identifier);
                    if ($key !== '') {
                        $lookup['sku'][$key][] = $variant;
                    }
                }

                foreach ([$variant->name, $variant->product?->name] as $name) {
                    $key = $this->normalize((string) $name);
                    if ($key !== '') {
                        $lookup['name'][$key][] = $variant;
                    }
                }
            });

        return $lookup;
    }

    private function customFieldQuery(int $appId, int $companyId): Builder
    {
        $crmDatabase = DB::connection('crm')->getDatabaseName();
        $legacyLead = SystemModules::getLegacyNamespace(Lead::class);
        $query = DB::connection('ecosystem')
            ->table('apps_custom_fields as custom_field')
            ->join($crmDatabase . '.leads as lead', function ($join): void {
                $join->on('lead.id', '=', 'custom_field.entity_id')
                    ->on('lead.companies_id', '=', 'custom_field.companies_id');
            })
            ->where('lead.apps_id', $appId)
            ->where('lead.companies_id', $companyId)
            ->where(fn ($status) => $status->whereNull('lead.status')->orWhere('lead.status', '<', 2))
            ->where('lead.is_deleted', false)
            ->where('custom_field.companies_id', $companyId)
            ->whereIn('custom_field.model_name', [Lead::class, $legacyLead])
            ->where('custom_field.name', LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value)
            ->where('custom_field.is_deleted', false)
            ->orderBy('custom_field.entity_id')
            ->orderByRaw('CASE WHEN custom_field.model_name = ? THEN 0 ELSE 1 END', [Lead::class])
            ->orderByRaw('COALESCE(custom_field.updated_at, custom_field.created_at) DESC')
            ->select([
                'custom_field.entity_id',
                'custom_field.users_id',
                'custom_field.value',
            ]);

        $from = trim((string) $this->option('from'));
        if ($from !== '') {
            $query->whereDate('lead.created_at', '>=', $from);
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $value
     * @param array{sku: array<string, list<Variants>>, name: array<string, list<Variants>>} $lookup
     * @return array{0: ?Variants, 1: string, 2: bool}
     */
    private function resolveVariant(array $value, array $lookup): array
    {
        foreach (['vin', 'stockNumber', 'stock_number', 'sku'] as $field) {
            $key = $this->normalize((string) ($value[$field] ?? ''));
            if ($key === '' || ! isset($lookup['sku'][$key])) {
                continue;
            }

            return count($lookup['sku'][$key]) === 1
                ? [$lookup['sku'][$key][0], $field, false]
                : [null, $field, true];
        }

        $name = implode(' ', array_filter([
            $value['year'] ?? $value['yearFrom'] ?? null,
            $value['make'] ?? null,
            $value['model'] ?? null,
            $value['trim'] ?? null,
        ]));
        $key = $this->normalize($name);
        if ($key === '' || ! isset($lookup['name'][$key])) {
            return [null, 'name', false];
        }

        return count($lookup['name'][$key]) === 1
            ? [$lookup['name'][$key][0], 'name', false]
            : [null, 'name', true];
    }

    private function normalize(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '', Str::ascii(trim($value))));
    }

    /** @param array<string, mixed> $value */
    private function price(array $value): ?float
    {
        $price = $value['price'] ?? null;

        return is_numeric($price) ? (float) $price : null;
    }

    /** @param list<int> $leadIds */
    private function reindexLeads(Apps $app, Companies $company, array $leadIds): void
    {
        collect($leadIds)->chunk(100)->each(function (Collection $chunk) use ($app, $company): void {
            $leads = Lead::query()
                ->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->whereIn('id', $chunk)
                ->notDeleted()
                ->with([
                    'people',
                    'variantInterests.variant.product',
                    'variantInterests.variant.channels',
                    'variantInterests.variant.variantAttributes.attribute',
                ])
                ->get();

            $leads->searchable();
        });
    }
}
