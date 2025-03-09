<?php

declare(strict_types=1);

namespace App\Console\Commands\Connectors\ESim;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;

class GenerateEsimRecommendationCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'kanvas:esim-generate-recommendation {app_id} {company_id}}';
    protected $description = 'Generate eSIM recommendation';

    public function handle(): void
    {
        $app = Apps::getById((int) $this->argument('app_id'));
        $this->overwriteAppService($app);

        $products = Products::fromApp($app)->with('variants.attributes')->get();

        foreach ($products as $product) {
            $recommendations = $this->mapProductRecommendationAttribute($product->variants->toArray());

            $product->addAttribute(
                'recommended_plans',
                $recommendations
            );

            $this->info('Set recommendation for product ' . $product->getId() . ' - ' . $product->name);
        }
    }

    protected function mapProductRecommendationAttribute(array $variants): array
    {
        if (empty($variants)) {
            return []; // Return empty if there are no variants
        }

        $sections = [];
        $sectionCounter = 1;

        // Sort variants by `esim_days` in ascending order
        usort($variants, function ($a, $b) {
            $aDays = $this->extractAttributeValue($a['attributes'], 'esim_days');
            $bDays = $this->extractAttributeValue($b['attributes'], 'esim_days');

            return $aDays <=> $bDays; // Ascending order
        });

        // Split sorted variants into two groups (low and high packages)
        $sortedSkus = array_column($variants, 'sku');
        $groupSize = max(1, (int)ceil(count($sortedSkus) / 2)); // Explicitly cast ceil result to int
        $groups = array_chunk($sortedSkus, $groupSize);

        $lowPackages = $groups[0] ?? [];
        $highPackages = $groups[1] ?? [];

        // Create Sections 1 to 4 with lower packages
        $lowPackageGroups = $this->groupVariantsIntoSections($lowPackages, 4);
        foreach ($lowPackageGroups as $group) {
            if ($sectionCounter > 4) {
                break;
            }
            $sections[] = [
                'name' => 'section_' . $sectionCounter,
                'variantSku' => $group,
            ];
            $sectionCounter++;
        }

        // Create Sections 5 to 10 with higher packages
        $highPackageGroups = $this->groupVariantsIntoSections($highPackages, 4);
        while (count($highPackageGroups) < 6) {
            $highPackageGroups[] = $highPackages; // Add repeated high packages if needed
        }
        $highPackageGroups = array_slice($highPackageGroups, 0, 6); // Ensure only 6 groups

        foreach ($highPackageGroups as $group) {
            $sections[] = [
                'name' => 'section_' . $sectionCounter,
                'variantSku' => $group,
            ];
            $sectionCounter++;
        }

        return $sections;
    }

    // Helper function to group variants into sections of specified size
    protected function groupVariantsIntoSections(array $variants, int $sectionSize): array
    {
        $groups = [];

        foreach (array_chunk($variants, $sectionSize) as $group) {
            $groups[] = $group;
        }

        return $groups;
    }

    // Extract attribute value by name from the attributes array
    protected function extractAttributeValue(array $attributes, string $attributeName): int
    {
        foreach ($attributes as $attribute) {
            if ($attribute['name'] === $attributeName) {
                return (int) $attribute['value'];
            }
        }

        return 0; // Default value if the attribute is not found
    }
}
