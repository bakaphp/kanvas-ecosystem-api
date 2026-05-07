<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;

class GenerateRoadsideAssistancePinAction
{
    public function __construct(
        protected readonly Order $order,
    ) {
    }

    public function execute(): string
    {
        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        // Idempotent: if PIN was already generated, return the stored plain PIN
        $existingHash = $assistanceCase['pin_hash']
            ?? $this->order->get(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN_HASH->value);

        if ($existingHash !== null) {
            return (string) ($this->order->get(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value) ?? '');
        }

        $pin = $this->generatePin();
        $pinHash = Hash::make($pin);

        $assistanceCase['pin_hash'] = $pinHash;
        $assistanceCase['pin_generated_at'] = Carbon::now()->toISOString();
        $assistanceCase['pin'] = $pin;

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();

        $this->order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value, $pin);
        $this->order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN_HASH->value, $pinHash);

        return $pin;
    }

    private function generatePin(): string
    {
        return (string) random_int(1000, 9999);
    }
}
