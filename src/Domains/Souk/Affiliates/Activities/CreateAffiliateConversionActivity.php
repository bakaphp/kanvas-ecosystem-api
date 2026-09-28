<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Activities;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateConversionAction;
use Kanvas\Souk\Affiliates\Enums\ConfigurationEnum;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Souk\Affiliates\Services\AffiliateReferrerService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class CreateAffiliateConversionActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Order $order, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (Order $order, $app, $integrationCompany, $params): array {
                $affiliateLink = $this->resolveAffiliateLink($order, $app, $params);

                if ($affiliateLink === null) {
                    $wooMeta = $order->metadata['woocommerce_meta_data'] ?? [];

                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'No affiliate link matched for order (referrer + mapping resolution failed)',
                        'order_id' => $order->getId(),
                        'affiliate_id' => $order->get('affiliate_id') ?? $order->metadata['affiliate_id'] ?? null,
                        'affiliate_shortcode' => $order->get('affiliate_shortcode') ?? $order->metadata['affiliate_shortcode'] ?? null,
                        'affiliate_referrer' => $wooMeta['_wc_order_attribution_referrer'] ?? null,
                    ]);
                }

                $order->set('affiliate_id', $affiliateLink->affiliates_id);
                $order->set('affiliate_link_id', $affiliateLink->id);
                $order->addMetadata('affiliate_id', $affiliateLink->affiliates_id);

                $conversion = new CreateAffiliateConversionAction($order)->execute();

                return [
                    'result' => true,
                    'message' => 'Affiliate conversion created successfully',
                    'order_id' => $order->getId(),
                    'conversion_id' => $conversion->getId(),
                    'affiliate_id' => $conversion->affiliates_id,
                    'commission_amount' => $conversion->commission_amount,
                    'commission_rate' => $conversion->commission_rate,
                    'commission_type' => $conversion->commission_type,
                    'status' => $conversion->status,
                ];
            },
            company: $order->company,
        );
    }

    /**
     * Resolve the AffiliateLink for an order.
     *
     * Preferred path: the WooCommerce attribution referrer's ?affiliate= slug matched against the
     * per-app referrer => short_code map (ConfigurationEnum::REFERRER_AFFILIATE_MAPPING). This is the
     * affiliate's real market — unlike affiliate_shortcode, which the eSIM sender fills with the order's
     * destination country (e.g. "JP") and cannot be resolved against the market mapping.
     *
     * Fallback path (referrer unset/unmapped): the legacy link-code / affiliate-id / shortcode mapping
     * carried on the order + workflow params.
     *
     * @param array<string, mixed> $params
     */
    private function resolveAffiliateLink(Order $order, Apps $app, array $params): ?AffiliateLink
    {
        $referrerMapping = $app->get(ConfigurationEnum::REFERRER_AFFILIATE_MAPPING->value);
        $referrerMapping = is_array($referrerMapping) ? $referrerMapping : [];

        $referrerShortCode = AffiliateReferrerService::resolveShortCodeFromMetadata(
            $referrerMapping,
            $order->metadata,
        );

        if ($referrerShortCode !== null) {
            $link = AffiliateLink::fromApp($app)
                ->where('short_code', $referrerShortCode)
                ->first();

            if ($link !== null) {
                return $link;
            }
        }

        $affiliateId = $params['affiliate_id'] ?? $order->get('affiliate_id') ?? $order->metadata['affiliate_id'] ?? null;
        $affiliateLinkCode = $order->get('affiliate_link_code') ?? $order->metadata['affiliate_link_code'] ?? null;
        $affiliateShortCode = $order->get('affiliate_shortcode') ?? $order->metadata['affiliate_shortcode'] ?? null;
        $useShortCode = $params['use_shortcode'] ?? false;
        $affiliateLinkMapping = is_array($params['affiliate_link_mapping'] ?? null) ? $params['affiliate_link_mapping'] : [];

        [$affiliateLinkCode, $affiliateId, $affiliateShortCode] = self::resolveFromMapping(
            $affiliateLinkCode !== null ? (string) $affiliateLinkCode : null,
            $affiliateId !== null ? (string) $affiliateId : null,
            $affiliateShortCode !== null ? (string) $affiliateShortCode : null,
            $affiliateLinkMapping,
        );

        return AffiliateLink::fromApp($app)
            ->where(function (Builder $query) use ($affiliateLinkCode, $affiliateId) {
                $query->where('short_code', $affiliateLinkCode)
                    ->orWhere('short_code', $affiliateId);
            })
            ->when($useShortCode && $affiliateShortCode !== null, function (Builder $query) use ($affiliateShortCode) {
                $query->where('custom_slug', $affiliateShortCode);
            })
            ->first();
    }

    /**
     * Match against the link mapping (['link_code' => affiliate_id]) in either direction: an order may
     * carry a link code ("RD" → "UA20") or the affiliate id itself ("ua20" → normalized "UA20").
     *
     * @param array<string, string> $mapping
     *
     * @return array{0: ?string, 1: ?string, 2: ?string} [affiliateLink, affiliateId, affiliateShortCode]
     */
    public static function resolveFromMapping(
        ?string $affiliateLink,
        ?string $affiliateId,
        ?string $affiliateShortCode,
        array $mapping,
    ): array {
        if ($mapping === []) {
            return [$affiliateLink, $affiliateId, $affiliateShortCode];
        }

        if ($affiliateLink !== null && isset($mapping[$affiliateLink])) {
            return [$mapping[$affiliateLink], $mapping[$affiliateLink], $affiliateLink];
        }

        if ($affiliateId !== null) {
            foreach ($mapping as $linkCode => $mappedAffiliateId) {
                if (strcasecmp($mappedAffiliateId, $affiliateId) === 0) {
                    return [$mappedAffiliateId, $mappedAffiliateId, $linkCode];
                }
            }
        }

        return [$affiliateLink, $affiliateId, $affiliateShortCode];
    }
}
