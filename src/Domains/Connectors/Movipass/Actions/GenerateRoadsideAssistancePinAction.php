<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
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

        $metadata = $this->order->metadata ?? [];
        $assistanceCase = $metadata['assistance_case'] ?? ($metadata['data']['assistance_case'] ?? []);

        $assistanceCase['pin_hash'] = Hash::make($pin);
        $assistanceCase['pin_generated_at'] = Carbon::now()->toISOString();

        $this->order->metadata = [
            ...$metadata,
            'assistance_case' => $assistanceCase,
            'data' => [
                ...($metadata['data'] ?? []),
                'assistance_case' => $assistanceCase,
            ],
        ];
        $this->order->saveQuietly();

        return $pin;
    }

    private function generatePin(int $length = 4): string
    {
        $pin = '';
        for ($i = 0; $i < $length; $i++) {
            $pin .= random_int(0, 9);
        }

        return $pin;
    }
}
