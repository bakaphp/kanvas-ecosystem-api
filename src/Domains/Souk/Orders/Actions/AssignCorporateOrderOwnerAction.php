<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\PasoRapido\Enums\CustomFieldEnum as PasoRapidoCustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;

class AssignCorporateOrderOwnerAction
{
    public const ACTOR_METADATA_KEY = 'corporate_actor_user_id';
    public const CREATOR_METADATA_KEY = ResolveCorporateOrderCreatorAction::CREATOR_METADATA_KEY;

    public function __construct(
        protected Order $order
    ) {
    }

    public function execute(): ?Order
    {
        $resolver = new ResolveCorporateOrderCreatorAction($this->order);
        $rawCompanyId = $resolver->metadataValue(ResolveCorporateOrderCreatorAction::COMPANY_METADATA_KEY);

        if ($rawCompanyId === null || ! $this->isProcessed()) {
            return null;
        }

        $creator = $resolver->execute();

        if ($creator === null) {
            Log::warning('Corporate order creator rejected, users_id left unchanged', [
                'order_id' => $this->order->getId(),
                'created_by_user_id' => $resolver->metadataValue(self::CREATOR_METADATA_KEY),
                'user_company_id' => $rawCompanyId,
            ]);

            return null;
        }

        if ((int) $this->order->users_id === $creator->getId()) {
            return null;
        }

        $metadata = $this->order->metadata ?? [];

        $this->order->metadata = [
            ...$metadata,
            'data' => [
                ...($metadata['data'] ?? []),
                // first writer wins: a retry must not overwrite the original owner captured on the first pass
                self::ACTOR_METADATA_KEY => $metadata['data'][self::ACTOR_METADATA_KEY] ?? (int) $this->order->users_id,
            ],
        ];
        $this->order->users_id = $creator->getId();
        $this->order->saveQuietly();
        $this->order->searchable();

        return $this->order;
    }

    private function isProcessed(): bool
    {
        if ($this->order->orderType?->name !== OrderTypeEnum::PASO_RAPIDO->value) {
            return true;
        }

        if (($this->order->metadata['data']['is_bulk_recharge'] ?? false) === true) {
            return collect($this->order->metadata['corporate_recharge_results'] ?? [])
                ->contains(fn (array $result) => ($result['status'] ?? null) === 'success');
        }

        return $this->order->payment_status === PaymentStatusEnum::PAID->value
            && $this->order->get(PasoRapidoCustomFieldEnum::PASO_RAPIDO_PAYMENT_STATUS->value) === PaymentStatusEnum::PAID->value;
    }
}
