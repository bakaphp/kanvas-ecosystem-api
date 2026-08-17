<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Insurance\Actions\AttachQuoteToOrderAction;
use Kanvas\Insurance\Providers\InsuranceProviderFactory;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

/**
 * Runs on order-created. The order arrives carrying the provider + quote the
 * customer picked in the comparator (order metadata); this pulls the real numbers
 * from the insurer and stamps them, so the rest of the flow reads the Order only.
 */
#[WorkflowAction]
class AttachInsuranceQuoteActivity extends KanvasActivity implements WorkflowActivityInterface
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        /** @var Order $order */
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
