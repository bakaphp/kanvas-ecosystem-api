<?php

declare(strict_types=1);

namespace App\GraphQL\Connector\Movipass\Mutations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\ChargeParkingExitAction;
use Kanvas\Connectors\Movipass\Actions\NotifyParkingEntryAction;
use Kanvas\Connectors\Movipass\Actions\ValidateParkingFinePaymentAction;

class ParkingMutation
{
    /**
     * @return array<string, mixed>
     */
    public function validateFinePayment(mixed $rootValue, array $request): array
    {
        return new ValidateParkingFinePaymentAction(
            app: app(Apps::class),
            token: (string) $request['token'],
            lotId: isset($request['lot_id']) ? (string) $request['lot_id'] : null,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function notifyEntry(mixed $rootValue, array $request): array
    {
        return new NotifyParkingEntryAction(
            app: app(Apps::class),
            qrCode: (string) $request['qr_code'],
            lotId: isset($request['lot_id']) ? (string) $request['lot_id'] : null,
        )->execute();
    }

    /**
     * @return array<string, mixed>
     */
    public function validateExit(mixed $rootValue, array $request): array
    {
        return new ChargeParkingExitAction(
            app: app(Apps::class),
            qrCode: (string) $request['qr_code'],
            amount: (float) $request['amount'],
            lotId: isset($request['lot_id']) ? (string) $request['lot_id'] : null,
        )->execute();
    }
}
