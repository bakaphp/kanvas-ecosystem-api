<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\AttachQuoteToOrderAction;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

#[WorkflowAction]
class AttachInsuranceQuoteActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        $metadata = (array) ($order->metadata['insurance'] ?? []);
        $providerName = (string) ($metadata['provider'] ?? '');
        $quoteNumber = (string) ($metadata['quote_number'] ?? '');

        if ($providerName === '' || $quoteNumber === '') {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Order carries no insurance quote',
            ];
        }

        if ((string) $order->get(InsuranceCustomFieldEnum::STATUS->value) !== '') {
            return [
                'order' => $order->getId(),
                'status' => 'skipped',
                'message' => 'Quote already attached to this order',
            ];
        }

        $provider = InsuranceProviderFactory::make($providerName, $app, $order->company);

        return $this->executeIntegration(
            entity: $order,
            app: $app,
            integration: $provider->integration(),
            additionalParams: $params,
            integrationOperation: function ($order, $app, $integrationCompany, $additionalParams) use ($providerName, $quoteNumber) {
                $quote = new AttachQuoteToOrderAction($order, $providerName, $quoteNumber)->execute();

                return [
                    'order' => $order->getId(),
                    'status' => 'success',
                    'provider' => $providerName,
                    'quoteNumber' => $quote->quoteNumber,
                    'total' => $quote->total,
                ];
            },
            company: $order->company,
        );
    }
}
