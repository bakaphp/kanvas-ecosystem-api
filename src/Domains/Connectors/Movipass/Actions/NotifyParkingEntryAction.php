<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\ParkingGateCodeEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Users\Models\Users;

class NotifyParkingEntryAction
{
    public const string PARKING_SESSIONS_FIELD = 'movipass_parking_sessions';

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $qrCode,
        protected readonly ?string $lotId = null,
    ) {
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     code: string,
     *     order_id: ?int,
     *     transaction_id: ?string,
     *     amount_charged: ?float,
     * }
     */
    public function execute(): array
    {
        try {
            $user = Users::getByUuid($this->qrCode, $this->app);
        } catch (ModelNotFoundException) {
            return $this->response(
                false,
                ParkingGateCodeEnum::NOT_FOUND,
                'No driver found for the scanned QR code.'
            );
        }

        $lotKey = $this->lotKey();
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = (array) ($user->get(self::PARKING_SESSIONS_FIELD) ?? []);
        $existing = $sessions[$lotKey] ?? null;

        if (is_array($existing) && isset($existing['entry_at']) && empty($existing['exit_at'])) {
            return $this->response(
                false,
                ParkingGateCodeEnum::ALREADY_INSIDE,
                'Driver is already inside this lot.'
            );
        }

        $sessions[$lotKey] = [
            'session_id' => (string) Str::uuid(),
            'lot_id' => $this->lotId,
            'entry_at' => Carbon::now()->toIso8601String(),
            'exit_at' => null,
        ];

        $user->set(self::PARKING_SESSIONS_FIELD, $sessions);

        return $this->response(
            true,
            ParkingGateCodeEnum::ENTRY_OK,
            'Entry recorded. Welcome.'
        );
    }

    protected function lotKey(): string
    {
        return $this->lotId ?? 'default';
    }

    /**
     * @return array{
     *     success: bool,
     *     message: string,
     *     code: string,
     *     order_id: ?int,
     *     transaction_id: ?string,
     *     amount_charged: ?float,
     * }
     */
    protected function response(
        bool $success,
        ParkingGateCodeEnum $code,
        string $message,
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'code' => $code->value,
            'order_id' => null,
            'transaction_id' => null,
            'amount_charged' => null,
        ];
    }
}
