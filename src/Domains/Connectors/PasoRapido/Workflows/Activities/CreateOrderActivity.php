<?php

namespace Kanvas\Connectors\PasoRapido\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\PasoRapido\DataTransferObject\PaymentConfirmData;
use Kanvas\Connectors\PasoRapido\Services\PasoRapidoService;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CreateOrderActivity extends KanvasActivity implements WorkflowActivityInterface
{   
    #[Override]
    public function execute(Model $order, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);
        if (isset($order->metadata['data']['paso_rapido_tag'])) {
            $variant = $order->items->first(function ($item) {
                return $item->variant->product?->attributes
                ->contains(fn ($attribute) => in_array($attribute->slug, ['tag']) && ! empty($attribute->value));
            })->variant;
    
            $product = $variant->product;

            $tag = $product->getAttributeByName('tag')->value;

            $pasoRapidoService = new PasoRapidoService($app, $order->company);
            $confirmPaymentResponse = $pasoRapidoService->confirmPayment(new PaymentConfirmData(
                reference: $tag,
                bankTransaction: $order->metadata['data']['paso_rapido_bank_transaction'],
                amount: $order->total,
                fiscalCredit: $order->metadata['data']['paso_rapido_fiscal_credit'],
                dni: $order->metadata['data']['paso_rapido_dni'],
            ));

            if ($confirmPaymentResponse->tag) {
                $order->update([
                    'metadata->data->paso_rapido_payment_status' => 'paid',
                ]);
            }
        }

        return [
            'order' => $order->getId(),
            'status' => 'success',
            'message' => 'Payment confirmed',
        ];
    }
}
