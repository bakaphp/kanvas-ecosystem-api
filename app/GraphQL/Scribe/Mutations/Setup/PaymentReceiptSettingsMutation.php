<?php

declare(strict_types=1);

namespace App\GraphQL\Scribe\Mutations\Setup;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Scribe\PaymentReceipts\Actions\ConfigurePaymentReceiptAction;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptSettingsService;

class PaymentReceiptSettingsMutation
{
    use ResolvesActingContext;

    public function configure(mixed $rootValue, array $request): array
    {
        $ctx = $this->actingContext();
        $input = $request['input'];

        return new ConfigurePaymentReceiptAction(
            app: $ctx->app,
            company: $ctx->company,
            user: $ctx->user,
            receivedBy: array_key_exists('received_by', $input) ? (string) $input['received_by'] : null,
            signature: $input['signature'] ?? null,
            seal: $input['seal'] ?? null,
        )->execute();
    }

    public function current(): array
    {
        return new PaymentReceiptSettingsService()->current($this->actingContext()->company);
    }
}
