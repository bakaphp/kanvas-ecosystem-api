<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Souk\Affiliates\Services\AffiliateReferrerService;
use Tests\TestCaseUnit;

final class AffiliateReferrerServiceTest extends TestCaseUnit
{
    /**
     * @return array<string, string>
     */
    private function mapping(): array
    {
        return [
            'ua_republica_dominicana' => 'UA20',
            'ua_do' => 'UA20',
            'ua_rd' => 'UA20',
            'ua_costa_rica' => 'UA20-1',
            'ua_cr' => 'UA20-1',
            'ua_el_salvador' => 'UA20-3',
            'ua_sv' => 'UA20-3',
        ];
    }

    public function testExtractsAffiliateSlugFromFullUrl(): void
    {
        $this->assertSame(
            'ua_elsalvador',
            AffiliateReferrerService::extractAffiliateSlug('https://example.com/?affiliate=ua_elsalvador')
        );
    }

    public function testExtractsAffiliateSlugFromBareQueryString(): void
    {
        $this->assertSame('ua_do', AffiliateReferrerService::extractAffiliateSlug('affiliate=ua_do'));
        $this->assertSame('ua_do', AffiliateReferrerService::extractAffiliateSlug('?affiliate=ua_do'));
    }

    public function testReturnsNullWhenNoAffiliateParam(): void
    {
        $this->assertNull(AffiliateReferrerService::extractAffiliateSlug('https://example.com/?utm_source=x'));
        $this->assertNull(AffiliateReferrerService::extractAffiliateSlug(''));
        $this->assertNull(AffiliateReferrerService::extractAffiliateSlug(null));
    }

    public function testResolvesShortCodeFromReferrer(): void
    {
        $this->assertSame(
            'UA20-1',
            AffiliateReferrerService::resolveShortCode($this->mapping(), 'https://example.com/?affiliate=ua_costa_rica')
        );
    }

    public function testResolvesAcrossSpacingAliasVariance(): void
    {
        // Referrer sends "ua_elsalvador" (no underscore); the map key is "ua_el_salvador" — normalization
        // (lowercase + strip non-alphanumerics) must still resolve it.
        $this->assertSame(
            'UA20-3',
            AffiliateReferrerService::resolveShortCode($this->mapping(), 'https://example.com/?affiliate=ua_elsalvador')
        );
    }

    public function testShortCodeAliasResolvesToSameAffiliate(): void
    {
        $this->assertSame(
            'UA20',
            AffiliateReferrerService::resolveShortCode($this->mapping(), '?affiliate=ua_rd')
        );
    }

    public function testReturnsNullWhenSlugUnmapped(): void
    {
        // "JP" is the eSIM destination country, never an affiliate market — must not resolve.
        $this->assertNull(
            AffiliateReferrerService::resolveShortCode($this->mapping(), 'https://example.com/?affiliate=jp')
        );
    }

    public function testReturnsNullWhenMappingEmpty(): void
    {
        $this->assertNull(
            AffiliateReferrerService::resolveShortCode([], 'https://example.com/?affiliate=ua_do')
        );
    }

    /**
     * Order metadata shape produced by the WooCommerce pull: the affiliate referrer lives under
     * metadata.woocommerce_meta_data._wc_order_attribution_referrer, while affiliate_shortcode holds the
     * eSIM destination country ("JP") that must NOT be used.
     */
    public function testResolvesFromWooCommerceOrderMetadata(): void
    {
        $metadata = [
            'woocommerce_meta_data' => [
                'trp_language' => 'es_ES',
                '_wc_order_attribution_referrer' => 'https://example.com/?affiliate=ua_elsalvador',
                '_wc_order_attribution_session_entry' => 'https://example.com/?affiliate=ua_elsalvador',
            ],
            'affiliate_id' => 'ua20',
            'affiliate_shortcode' => 'JP',
            'affiliate_link_code' => 'JP',
        ];

        $this->assertSame(
            'UA20-3',
            AffiliateReferrerService::resolveShortCodeFromMetadata($this->mapping(), $metadata)
        );
    }

    public function testFallsBackToSessionEntryWhenReferrerMissing(): void
    {
        $metadata = [
            'woocommerce_meta_data' => [
                '_wc_order_attribution_session_entry' => 'https://example.com/?affiliate=ua_do',
            ],
        ];

        $this->assertSame(
            'UA20',
            AffiliateReferrerService::resolveShortCodeFromMetadata($this->mapping(), $metadata)
        );
    }

    public function testReturnsNullWhenMetadataHasNoAttribution(): void
    {
        $this->assertNull(
            AffiliateReferrerService::resolveShortCodeFromMetadata($this->mapping(), ['woocommerce_meta_data' => ['trp_language' => 'es_ES']])
        );
        $this->assertNull(AffiliateReferrerService::resolveShortCodeFromMetadata($this->mapping(), null));
    }
}
