<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Actions\ConfigureB2BCompanyPricingAction;
use Kanvas\Souk\Services\B2BConfigurationService;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class B2BCompanyPriceConfigurationActivity extends KanvasActivity
{
    public function execute(Companies $buyerCompany, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        /** @var Companies $mainAppCompany */
        $mainAppCompany = B2BConfigurationService::getConfiguredB2BCompany($app, $buyerCompany);
        $productTypes = $params['product_types'] ?? [];
        $discountedPricePercentage = $buyerCompany->get('b2b_discounted_price_percentage') ?? 0.00;

        if (empty($discountedPricePercentage) || (float) $discountedPricePercentage <= 0) {
            return [
                'status' => 'error',
                'message' => 'Discounted price percentage is not set for the buyer company.',
            ];
        }

        return $this->executeIntegration(
            entity: $buyerCompany,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function () use ($app, $buyerCompany, $mainAppCompany, $productTypes, $discountedPricePercentage) {
                return new ConfigureB2BCompanyPricingAction(
                    app: $app,
                    buyerCompany: $buyerCompany,
                    mainAppCompany: $mainAppCompany,
                    discountedPricePercentage: (float) $discountedPricePercentage,
                    productTypes: $productTypes,
                )->execute();
            },
            company: $mainAppCompany
        );
    }
}
