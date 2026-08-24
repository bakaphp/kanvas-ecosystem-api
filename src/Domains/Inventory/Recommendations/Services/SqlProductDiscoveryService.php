<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\DataTransferObject\ProductIntent;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;
use Override;

/**
 * Keyword fallback for tenants with no engine, and where an unreachable one
 * lands. Deliberately dumb — it matches words, it does not read the sentence.
 */
class SqlProductDiscoveryService implements ProductDiscoveryInterface
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly SearchTermTokenizerService $tokenizer,
    ) {
    }

    /**
     * @param float[]|null $tasteVector ignored — SQL has no vector space
     *
     * @return list<int>
     */
    #[Override]
    public function search(ProductIntent $intent, int $limit, ?array $tasteVector = null): array
    {
        $terms = $this->tokenizer->tokenize($intent->sentence);
        $query = $this->baseQuery();

        if ($terms !== []) {
            $query->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $escaped = '%' . addcslashes($term, '%_\\') . '%';
                    $builder->orWhere('name', 'LIKE', $escaped)
                        ->orWhere('description', 'LIKE', $escaped);
                }
            });
        }

        return $query->orderByDesc('rating')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** Price lives on the variant channel; the caller filters it after hydration. */
    private function baseQuery(): Builder
    {
        $query = Products::fromApp($this->app)
            ->notDeleted()
            ->where('is_published', 1);

        if (! (bool) $this->app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value)) {
            $query->where('companies_id', $this->company->getId());
        }

        return $query;
    }
}
