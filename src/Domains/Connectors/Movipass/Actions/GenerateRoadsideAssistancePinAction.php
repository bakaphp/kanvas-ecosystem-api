<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $pin = $this->generatePin();
        $pinHash = Hash::make($pin);

        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $assistanceCase['pin_hash'] = $pinHash;
        $assistanceCase['pin_generated_at'] = Carbon::now()->toISOString();

        $metadata['assistance_case'] = $assistanceCase;
        $this->order->metadata = $metadata;
        $this->order->saveQuietly();

        // Store as custom fields to prevent metadata overwrites
        $this->order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN->value, $pin);
        $this->order->set(CustomFieldEnum::ROADSIDE_ASSISTANCE_PIN_HASH->value, $pinHash);

        return $pin;
    }

    private function generatePin(int $length = 4): string
    {
        return Str::padLeft((string) random_int(0, (10 ** $length) - 1), $length, '0');
    }
}
