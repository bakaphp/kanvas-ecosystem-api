<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Services;

use Illuminate\Support\Str;

class AffiliateReferrerService
{
    public static function extractAffiliateSlug(?string $referrer): ?string
    {
        if ($referrer === null || trim($referrer) === '') {
            return null;
        }

        $query = parse_url($referrer, PHP_URL_QUERY);
        if (! is_string($query) || $query === '') {
            // Referrer might already be a bare query string ("affiliate=ua_do") with no scheme/host.
            $query = str_contains($referrer, '=') ? ltrim($referrer, '?') : '';
        }

        if ($query === '') {
            return null;
        }

        parse_str($query, $params);
        $slug = $params['affiliate'] ?? null;

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * Resolve an affiliate_links.short_code from the referrer using the per-app slug => short_code map.
     *
     * Both the referrer slug and the map keys are normalized (lowercased, non-alphanumerics stripped)
     *
     * @param array<string, string> $mapping
     */
    public static function resolveShortCode(array $mapping, ?string $referrer): ?string
    {
        $slug = self::extractAffiliateSlug($referrer);

        if ($slug === null || $mapping === []) {
            return null;
        }

        $normalizedSlug = Str::slug($slug, '');

        foreach ($mapping as $key => $shortCode) {
            if (Str::slug($key, '') === $normalizedSlug) {
                return (string) $shortCode;
            }
        }

        return null;
    }

    /**
     * Resolve a short_code from an order's metadata as produced by the WooCommerce pull. The affiliate
     * attribution lives under woocommerce_meta_data as WooCommerce's own attribution keys — the referrer
     * carries the real market slug (?affiliate=ua_...), unlike affiliate_shortcode which the eSIM sender
     * fills with the order's destination country.
     *
     * @param array<string, string> $mapping
     * @param array<string, mixed>|null $metadata
     */
    public static function resolveShortCodeFromMetadata(array $mapping, ?array $metadata): ?string
    {
        $wooMeta = is_array($metadata['woocommerce_meta_data'] ?? null) ? $metadata['woocommerce_meta_data'] : [];

        $referrer = $wooMeta['_wc_order_attribution_referrer']
            ?? $wooMeta['_wc_order_attribution_session_entry']
            ?? null;

        return self::resolveShortCode($mapping, is_string($referrer) ? $referrer : null);
    }
}
