<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ScrapperApi;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
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
        /** @var Apps $app */
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

        // App-wide set of products currently featured — small (only the tagged ones), loaded once.
        $taggedProductIds = empty($homepageTagIds) ? [] : TagEntity::query()
            ->where('taggable_type', Products::class)
            ->where('is_deleted', 0)
            ->whereIn('tags_id', $homepageTagIds)
            ->pluck('entity_id')
            ->unique()
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
            $inCategory = fn () => Products::query()
                ->fromApp($app)
                ->fromCompany($company)
                ->notDeleted()
                ->whereHas('categories', fn ($query) => $query->where('categories.id', $category->getId()));

            $taggedInCategory = empty($taggedProductIds)
                ? []
                : $inCategory()->whereIn('id', $taggedProductIds)->pluck('id')->all();

            // Candidates exclude anything featured anywhere so the rotation lands on fresh products.
            $candidateCount = $inCategory()->whereNotIn('id', $taggedProductIds)->count();

            if ($candidateCount < $countToAssign) {
                $this->warn(sprintf(
                    'Skipping category "%s" (id %d): only %d product(s) available to tag, need %d.',
                    $category->name,
                    $category->getId(),
                    $candidateCount,
                    $countToAssign
                ));

                continue;
            }

            if (! empty($taggedInCategory)) {
                TagEntity::query()
                    ->where('taggable_type', Products::class)
                    ->whereIn('tags_id', $homepageTagIds)
                    ->whereIn('entity_id', $taggedInCategory)
                    ->delete();
            }

            $assigned = 0;
            foreach (
                $inCategory()
                    ->whereNotIn('id', $taggedProductIds)
                    ->inRandomOrder()
                    ->limit($countToAssign)
                    ->cursor() as $product
            ) {
                $product->addTag($canonicalTag, $app, company: $company);
                $assigned++;
            }

            $rotatedCategories++;

            $this->info(sprintf(
                'Category "%s" (id %d): removed Homepage tag from %d, assigned to %d.',
                $category->name,
                $category->getId(),
                count($taggedInCategory),
                $assigned
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
