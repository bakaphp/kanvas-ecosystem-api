<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Actions;

use Illuminate\Support\Facades\Log;
use Kanvas\Souk\Orders\Models\Order;

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

        if ($rawCompanyId === null) {
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
}
