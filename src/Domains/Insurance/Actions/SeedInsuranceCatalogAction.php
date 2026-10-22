<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Insurance\Services\InsuranceProductCategoryMapper;

class SeedInsuranceCatalogAction
{
    private const DEFAULT_VALIDITY_DAYS = 365;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly CompanyInterface $company,
    ) {
    }

    public function execute(): array
    {
        return DB::connection('insurance')->transaction(function (): array {
            $created = [];

            foreach ($this->definitions() as $definition) {
                $insurer = $this->firstOrCreateInsurer($definition['provider'], $definition['name']);

                foreach ($definition['products'] as $code => $label) {
                    $plan = $this->createPlanIfMissing($insurer, $definition['provider'], $code, $label);

                    if ($plan !== null) {
                        $created[] = $plan;
                    }
                }
            }

            return $created;
        });
    }

    protected function definitions(): array
    {
        return [
            [
                'provider' => UniversalSegurosProvider::NAME,
                'name' => 'Universal Seguros',
                'products' => array_column(
                    array_map(fn (ProductEnum $product): array => [$product->value, $product->label()], ProductEnum::cases()),
                    1,
                    0
                ),
            ],
            [
                'provider' => HumanoProvider::NAME,
                'name' => 'Humano',
                'products' => array_column(
                    array_map(fn (PlanEnum $plan): array => [$plan->value, $plan->label()], PlanEnum::cases()),
                    1,
                    0
                ),
            ],
        ];
    }

    protected function firstOrCreateInsurer(string $provider, string $name): Insurer
    {
        $slug = Str::slug($name);

        $existing = Insurer::query()
            ->withoutGlobalScopes()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('slug', $slug)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $insurer = new Insurer();
        $insurer->apps_id = $this->app->getId();
        $insurer->companies_id = $this->company->getId();
        $insurer->name = $name;
        $insurer->slug = $slug;
        $insurer->provider = $provider;
        $insurer->status = InsuranceCatalogStatusEnum::ACTIVE;
        $insurer->saveOrFail();

        return $insurer;
    }

    protected function createPlanIfMissing(
        Insurer $insurer,
        string $provider,
        string $code,
        string $label,
    ): ?InsurancePlan {
        $slug = Str::slug($code);

        $exists = InsurancePlan::query()
            ->withoutGlobalScopes()
            ->where('insurers_id', $insurer->getId())
            ->where('slug', $slug)
            ->exists();

        if ($exists) {
            return null;
        }

        $plan = new InsurancePlan();
        $plan->insurers_id = $insurer->getId();
        $plan->name = $label;
        $plan->slug = $slug;
        $plan->type = InsuranceProductCategoryMapper::categoryFor($provider, $code);
        $plan->external_code = $code;
        $plan->validity_days = self::DEFAULT_VALIDITY_DAYS;
        $plan->status = InsuranceCatalogStatusEnum::ACTIVE;
        $plan->saveOrFail();

        return $plan;
    }
}
