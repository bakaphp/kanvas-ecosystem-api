<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Recommendations\Contracts\ProductDiscoveryInterface;
use Kanvas\Inventory\Recommendations\Discovery\DiscoveryQuery;
use Kanvas\Inventory\Variants\Models\Variants;
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
     * Constraints are not applied here: price and numeric attributes live on
     * variants and attribute rows, and the caller re-checks every hard constraint
     * against the database after this nominates ids.
     *
     * @param float[]|null $tasteVector ignored — SQL has no vector space
     *
     * @return list<int>
     */
    #[Override]
    public function search(DiscoveryQuery $query, int $limit, ?array $tasteVector = null): array
    {
        $exact = $this->exactMatches($this->tokenizer->identifiers($query->text), $limit);
        $terms = $this->tokenizer->tokenize($query->text);

        // A sentence the tokenizer emptied ("X5", "123456") would otherwise skip the
        // `where` and return the whole catalog by rating.
        if ($terms === []) {
            return $exact;
        }

        $matches = $this->baseQuery()
            ->where(function (Builder $builder) use ($terms): void {
                foreach ($terms as $term) {
                    $escaped = '%' . addcslashes($term, '%_\\') . '%';
                    $builder->orWhere('name', 'LIKE', $escaped)
                        ->orWhere('description', 'LIKE', $escaped);
                }
            })
            ->orderByDesc('rating')
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return array_slice(array_values(array_unique([...$exact, ...$matches])), 0, $limit);
    }

    #[Override]
    public function supportsVectors(): bool
    {
        return false;
    }

    #[Override]
    public function canFilterOn(string $field): bool
    {
        return false;
    }

    /**
     * @param list<string> $identifiers
     *
     * @return list<int>
     */
    private function exactMatches(array $identifiers, int $limit): array
    {
        if ($identifiers === []) {
            return [];
        }

        $variantProductIds = Variants::query()
            ->where('apps_id', $this->app->getId())
            ->where('is_deleted', 0)
            ->whereIn('sku', $identifiers)
            ->pluck('products_id');

        return $this->baseQuery()
            ->where(function (Builder $builder) use ($identifiers, $variantProductIds): void {
                $builder->whereIn('slug', $identifiers)
                    ->orWhereIn('id', $variantProductIds);
            })
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

        if (! ProductDiscoverySettings::crossCompanyVariants($this->app)) {
            $query->where('companies_id', $this->company->getId());
        }

        return $query;
    }
}
