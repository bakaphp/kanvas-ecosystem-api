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

        // Define day ranges for each section
        $sectionRanges = [
            'section_1' => [1, 1],          // 1 day
            'section_2' => [2, 3],          // 2-3 days
            'section_3' => [4, 5],          // 4-5 days
            'section_4' => [6, 7],          // 6-7 days
            'section_5' => [8, 10],         // 8-10 days
            'section_6' => [11, 14],        // 11-14 days
            'section_7' => [15, 30],         // 15-30 days
        ];

        // Look for data size attribute in the first variant
        $dataSizeAttributeName = $this->findDataSizeAttributeName($variants);

        // Extract data sizes from variant attributes
        $dataSizeMap = [];
        foreach ($variants as $variant) {
            // Try to get data size from the identified attribute
            $dataSize = 0;
            if ($dataSizeAttributeName) {
                $dataSize = $this->extractAttributeValue($variant['attributes'], $dataSizeAttributeName);
            }

            // If we couldn't find it in attributes, try to parse it from the SKU as fallback
            if ($dataSize === 0) {
                // Extract GB from the variant SKU using regex
                preg_match('/(\d+)GB/i', $variant['sku'], $matches);
                $dataSize = isset($matches[1]) ? (int)$matches[1] : 0;
            }

            $dataSizeMap[$variant['sku']] = $dataSize;
        }

        // Define tier cutoffs
        $lowerTierMax = 5; // 5GB and below go in lower tier
        $midTierMax = 10;  // 6-10GB go in mid tier
        // 11GB+ go in high tier

        // Group variants by data size tiers
        $lowerTierVariants = [];
        $midTierVariants = [];
        $highTierVariants = [];

        foreach ($dataSizeMap as $sku => $dataSize) {
            if ($dataSize <= $lowerTierMax) {
                $lowerTierVariants[$sku] = $dataSize;
            } elseif ($dataSize <= $midTierMax) {
                $midTierVariants[$sku] = $dataSize;
            } else {
                $highTierVariants[$sku] = $dataSize;
            }
        }

        // Sort each tier by data size (in descending order)
        arsort($lowerTierVariants);
        arsort($midTierVariants);
        arsort($highTierVariants);

        // Initialize sections array
        $sections = [];

        // Create recommendations for each section
        foreach ($sectionRanges as $sectionName => $dayRange) {
            $sectionNumber = (int)substr($sectionName, -1);
            $sectionVariants = [];

            if ($sectionNumber <= 4) {
                // For shorter stays (sections 1-4), only use lower tier variants
                $sectionVariants = array_keys($lowerTierVariants);

                // Only use mid/high tier if we don't have enough lower tier variants
                if (count($sectionVariants) < 4) {
                    $sectionVariants = array_merge(
                        $sectionVariants,
                        array_keys($midTierVariants)
                    );
                }
            } elseif ($sectionNumber <= 6) {
                // For medium stays (sections 5-6), use mid and high tier variants
                $sectionVariants = array_merge(
                    array_keys($highTierVariants),
                    array_keys($midTierVariants)
                );

                // Only use lower tier if we don't have enough mid/high tier variants
                if (count($sectionVariants) < 4) {
                    $sectionVariants = array_merge(
                        $sectionVariants,
                        array_keys($lowerTierVariants)
                    );
                }
            } else {
                // For longest stays (section 7), prioritize highest tier variants
                $sectionVariants = array_keys($highTierVariants);

                // Add mid tier if needed
                if (count($sectionVariants) < 4) {
                    $sectionVariants = array_merge(
                        $sectionVariants,
                        array_keys($midTierVariants)
                    );
                }

                // Add lower tier if still needed
                if (count($sectionVariants) < 4) {
                    $sectionVariants = array_merge(
                        $sectionVariants,
                        array_keys($lowerTierVariants)
                    );
                }
            }

            // Sort the final list by data size (highest first)
            usort($sectionVariants, function ($a, $b) use ($dataSizeMap) {
                return $dataSizeMap[$b] <=> $dataSizeMap[$a];
            });

            // Limit to 4 options
            $sectionVariants = array_slice($sectionVariants, 0, 4);

            // Add section to results
            $sections[] = [
                'name' => $sectionName,
                'variantSku' => $sectionVariants,
            ];
        }

        return $sections;
    }

    // Try to find the attribute name for data size
    protected function findDataSizeAttributeName(array $variants): ?string
    {
        // List of possible attribute names for data size
        $possibleNames = [
            'data_size',
            'data_amount',
            'gb',
            'data_gb',
            'data_package',
            'esim_data',
            'esim_gb',
        ];

        if (empty($variants)) {
            return null;
        }

        // Check the first variant for any of these attributes
        foreach ($variants[0]['attributes'] as $attribute) {
            if (isset($attribute['name'])) {
                $name = strtolower($attribute['name']);
                if (in_array($name, $possibleNames) || strpos($name, 'data') !== false || strpos($name, 'gb') !== false) {
                    return $attribute['name'];
                }
            }
        }

        return null;
    }

    // Extract attribute value by name from the attributes array
    protected function extractAttributeValue(array $attributes, string $attributeName): int
    {
        foreach ($attributes as $attribute) {
            if (isset($attribute['name']) && $attribute['name'] === $attributeName && isset($attribute['value'])) {
                return (int)$attribute['value'];
            }
        }

        return 0; // Default value if the attribute is not found
    }
}
