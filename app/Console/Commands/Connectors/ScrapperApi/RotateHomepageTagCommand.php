<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Categories\Models\Categories;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Social\Tags\Models\Tag;
use Kanvas\Social\Tags\Models\TagEntity;

class RotateHomepageTagCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:scrapper-rotate-homepage-tag
                            {app_id : The application ID}
                            {company_id : The company ID}
                            {--count=5 : Number of products to tag per category}
                            {--tag=Homepage : Canonical tag name to assign}';

    protected $description = 'Rotate the Homepage tag per category: remove it (any case) from current products and assign it to N others';

    public function handle(): int
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $company = Companies::getById((int) $this->argument('company_id'));

        $this->overwriteAppService($app);

        $countToAssign = (int) $this->option('count');
        $canonicalTag = (string) $this->option('tag');

        // Raw lowercase match so 'Homepage', 'HomePage', 'homepage', ... all resolve.
        $homepageTagIds = Tag::query()
            ->fromApp($app)
            ->whereRaw('LOWER(name) = ?', ['homepage'])
            ->pluck('id')
            ->all();

        $categories = Categories::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->get();

        if ($categories->isEmpty()) {
            $this->warn('No categories found for the given app and company.');

            return self::SUCCESS;
        }

        $rotatedCategories = 0;

        foreach ($categories as $category) {
            $products = Products::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->whereHas('categories', fn ($query) => $query->where('categories.id', $category->getId()))
                ->get();

            $productIds = $products->pluck('id')->all();

            $taggedIds = empty($homepageTagIds) ? [] : TagEntity::query()
                ->where('taggable_type', Products::class)
                ->where('is_deleted', 0)
                ->whereIn('tags_id', $homepageTagIds)
                ->whereIn('entity_id', $productIds)
                ->pluck('entity_id')
                ->unique()
                ->all();

            /** @var Collection<int, Products> $candidates */
            $candidates = $products->whereNotIn('id', $taggedIds)->values();

            if ($candidates->count() < $countToAssign) {
                $this->warn(sprintf(
                    'Skipping category "%s" (id %d): only %d product(s) available to tag, need %d.',
                    $category->name,
                    $category->getId(),
                    $candidates->count(),
                    $countToAssign
                ));

                continue;
            }

            if (! empty($homepageTagIds) && ! empty($taggedIds)) {
                TagEntity::query()
                    ->where('taggable_type', Products::class)
                    ->whereIn('tags_id', $homepageTagIds)
                    ->whereIn('entity_id', $taggedIds)
                    ->delete();
            }

            $toTag = $candidates->shuffle()->take($countToAssign);

            foreach ($toTag as $product) {
                $product->addTag($canonicalTag, $app, company: $company);
            }

            $rotatedCategories++;

            $this->info(sprintf(
                'Category "%s" (id %d): removed Homepage tag from %d, assigned to %d.',
                $category->name,
                $category->getId(),
                count($taggedIds),
                $toTag->count()
            ));
        }

        $this->info('');
        $this->info('=== Rotation Summary ===');
        $this->info('Categories processed: ' . $categories->count());
        $this->info('Categories rotated: ' . $rotatedCategories);
        $this->info('========================');

        return self::SUCCESS;
    }
}
