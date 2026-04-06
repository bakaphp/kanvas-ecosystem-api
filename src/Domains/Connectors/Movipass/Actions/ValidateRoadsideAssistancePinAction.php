<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Facades\Hash;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

class ValidateRoadsideAssistancePinAction
{
    public function __construct(
        protected readonly Order $order,
        protected readonly string $pin,
    ) {
    }

    /**
     * Validate the PIN. Returns true if valid, throws on failure.
     */
    public function execute(): bool
    {
        if ($this->order->orderType->name !== OrderTypeEnum::ROADSIDE_ASSISTANCE->value) {
            throw new ValidationException('This order is not a roadside assistance case');
        }

        $currentStatusSlug = $this->order->orderStatus?->slug;

        if ($currentStatusSlug !== MovipassOrderStatusEnum::PROVIDER_ASSIGNED->value) {
            throw new ValidationException(
                'PIN can only be validated when the order is in provider_assigned status, current status: ' . ($currentStatusSlug ?? 'unknown')
            );
        }

        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        // Custom field is the source of truth, fallback to metadata
        $pinHash = $this->order->get(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN_HASH->value)
            ?? $assistanceCase['pin_hash']
            ?? null;

        if ($pinHash === null) {
            throw new ValidationException('No PIN has been generated for this case');
        }

        if (! Hash::check($this->pin, $pinHash)) {
            throw new ValidationException('Invalid PIN');
        }

        return true;
    }
}
