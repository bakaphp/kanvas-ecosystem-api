<?php

declare(strict_types=1);

namespace Kanvas\Souk\Affiliates\Activities;

use Illuminate\Database\Eloquent\Builder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Affiliates\Actions\CreateAffiliateConversionAction;
use Kanvas\Souk\Affiliates\Models\AffiliateLink;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
            integrationOperation: function (Order $order, $app, $integrationCompany, $additionalParams): array {
                $affiliateId = $order->get('affiliate_id') ?? $order->metadata['affiliate_id'] ?? null;
                $affiliateLink = $order->get('affiliate_link_code') ?? $order->metadata['affiliate_link_code'] ?? null;

                $affiliateLink = AffiliateLink::fromApp($app)
                    ->where('companies_id', $order->companies_id)
                    ->where(function (Builder $query) use ($affiliateLink, $affiliateId) {
                        $query->where('code', $affiliateLink)
                            ->orWhere('code', $affiliateId);
                    })
                    ->first();

                //$affiliateId = $affiliateLink?->affiliates_id ?? $affiliateId;

                if ($affiliateLink === null) {
                    return $this->failWorkflow([
                        'result' => false,
                        'message' => 'Order does not have affiliate_id in custom_fields or metadata',
                        'order_id' => $order->getId(),
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
}
