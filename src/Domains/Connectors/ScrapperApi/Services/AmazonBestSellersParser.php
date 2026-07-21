<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ScrapperApi\Services;

class AmazonBestSellersParser
{
    /**
     * Each best-seller block on the rendered page is a fixed 4-link shape:
     * rank + image/link, title/link, "_x out of 5 stars_ reviews"/link, price/link,
     * all pointing at the same /dp/ASIN. Anchor on the repeated ASIN so we never
     * splice fields from two different products together.
     */
    private const string PRODUCT_PATTERN = '/#(\d+)\s+\[!\[[^\]]*\]\(([^)]+)\)\]\([^)]*?\/dp\/([A-Z0-9]{10})[^)]*\)\s+\[([^\]]+)\]\([^)]*?\/dp\/\3[^)]*\)\s+\[_([\d.]+) out of 5 stars_\s*([\d,]+)\]\([^)]*\)\s+\[\$([\d,.]+)\]/u';

    /**
     * Parse the Amazon Best Sellers markdown into products grouped by the
     * "## Best Sellers in <Category>" sections.
     *
     * @return array<int, array{category: string, products: array<int, array<string, mixed>>}>
     */
    public static function parse(string $markdown): array
    {
        preg_match_all(
            '/^##[ \t]+Best Sellers in[ \t]+(.+?)[ \t]*$/m',
            $markdown,
            $headings,
            PREG_OFFSET_CAPTURE | PREG_SET_ORDER
        );

        $result = [];
        $total = count($headings);

        for ($i = 0; $i < $total; $i++) {
            $name = trim($headings[$i][1][0]);
            $start = $headings[$i][0][1] + strlen($headings[$i][0][0]);
            $end = $i + 1 < $total ? $headings[$i + 1][0][1] : strlen($markdown);

            $products = self::parseProducts(substr($markdown, $start, $end - $start));

            if (! empty($products)) {
                $result[] = [
                    'category' => $name,
                    'products' => $products,
                ];
            }
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function parseProducts(string $section): array
    {
        preg_match_all(self::PRODUCT_PATTERN, $section, $matches, PREG_SET_ORDER);

        $products = [];
        foreach ($matches as $match) {
            $products[] = [
                'rank' => (int) $match[1],
                'asin' => $match[3],
                'name' => trim($match[4]),
                'image' => $match[2],
                'rating' => (float) $match[5],
                'reviews' => (int) str_replace(',', '', $match[6]),
                'price' => (float) str_replace(',', '', $match[7]),
            ];
        }

        return $products;
    }
}
