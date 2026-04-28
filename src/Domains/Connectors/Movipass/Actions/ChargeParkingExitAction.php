<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Contracts\AppInterface;
use Bavix\Wallet\Exceptions\InsufficientFunds;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\ParkingGateCodeEnum;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Souk\Wallet\Enums\ConfigurationEnum as SoukWalletConfigurationEnum;
use Kanvas\Souk\Wallet\Traits\HasWalletHolderTrait;
use Kanvas\Users\Models\Users;
use Throwable;

class ChargeParkingExitAction
{
    use HasWalletHolderTrait;

    public function __construct(
        protected readonly AppInterface $app,
        protected readonly string $qrCode,
        protected readonly float $amount,
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
        if ($this->amount <= 0) {
            return $this->response(
                false,
                ParkingGateCodeEnum::FAILED,
                'Amount must be greater than zero.'
            );
        }

        try {
            $user = Users::getByUuid($this->qrCode, $this->app);
        } catch (ModelNotFoundException) {
            return $this->response(
                false,
                ParkingGateCodeEnum::NOT_FOUND,
                'No driver found for the scanned QR code.'
            );
        }

        $session = $this->resolveSession($user);

        $walletHolder = $this->getWalletHolder($this->app, $user);
        $tag = SoukWalletConfigurationEnum::WALLET_DEFAULT_NAME->value;
        $wallet = $walletHolder->createAppWallet($this->app, ['name' => $tag]);
        $balance = (float) $wallet->balanceFloatNum;

        $allowNegative = $this->allowsNegativeBalance();
        $negativeLimit = $this->app->get(ConfigurationEnum::PARKING_NEGATIVE_BALANCE_LIMIT->value) ?? 0.0;

        if ($balance < $this->amount && (! $allowNegative || ($balance - $this->amount) < -$negativeLimit)) {
            $this->stampInsufficient($user, $session);

            return $this->response(
                false,
                ParkingGateCodeEnum::INSUFFICIENT_FUNDS,
                'Insufficient funds. Please top up your wallet to exit.',
            );
        }

        try {
            $transaction = $allowNegative
                ? $wallet->forceWithdrawFloat($this->amount, $this->transactionMeta($session))
                : $wallet->withdrawFloat($this->amount, $this->transactionMeta($session));
        } catch (InsufficientFunds) {
            return $this->response(
                false,
                ParkingGateCodeEnum::INSUFFICIENT_FUNDS,
                'Insufficient funds. Please top up your wallet to exit.',
            );
        } catch (Throwable $e) {
            return $this->response(
                false,
                ParkingGateCodeEnum::FAILED,
                'Charge failed: ' . $e->getMessage(),
            );
        }

        $this->closeSession($user, $session);

        return $this->response(
            true,
            ParkingGateCodeEnum::EXIT_OK,
            'Exit charge applied. Have a safe trip.',
            transactionId: (string) $transaction->getKey(),
            amountCharged: $this->amount,
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveSession(Users $user): array
    {
        $lotKey = $this->lotKey();
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = (array) ($user->get(NotifyParkingEntryAction::PARKING_SESSIONS_FIELD) ?? []);
        $existing = $sessions[$lotKey] ?? null;

        if (is_array($existing) && isset($existing['entry_at']) && empty($existing['exit_at'])) {
            return $existing;
        }

        return [
            'session_id' => (string) Str::uuid(),
            'lot_id' => $this->lotId,
            'entry_at' => null,
            'exit_at' => null,
            'synthetic' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function closeSession(Users $user, array $session): void
    {
        $lotKey = $this->lotKey();
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = (array) ($user->get(NotifyParkingEntryAction::PARKING_SESSIONS_FIELD) ?? []);

        $session['exit_at'] = Carbon::now()->toIso8601String();
        $session['amount_charged'] = $this->amount;

        $sessions[$lotKey] = $session;
        $user->set(NotifyParkingEntryAction::PARKING_SESSIONS_FIELD, $sessions);
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function stampInsufficient(Users $user, array $session): void
    {
        $lotKey = $this->lotKey();
        /** @var array<string, array<string, mixed>> $sessions */
        $sessions = (array) ($user->get(NotifyParkingEntryAction::PARKING_SESSIONS_FIELD) ?? []);

        $session['last_insufficient_at'] = Carbon::now()->toIso8601String();
        $session['last_insufficient_amount'] = $this->amount;

        $sessions[$lotKey] = $session;
        $user->set(NotifyParkingEntryAction::PARKING_SESSIONS_FIELD, $sessions);
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    protected function transactionMeta(array $session): array
    {
        return [
            'order_id' => null,
            'type' => 'parking_exit',
            'description' => 'Parking exit charge',
            'lot_id' => $this->lotId,
            'session_id' => $session['session_id'] ?? null,
            'entry_at' => $session['entry_at'] ?? null,
            'exit_at' => Carbon::now()->toIso8601String(),
            'synthetic_session' => $session['synthetic'] ?? false,
        ];
    }

    protected function allowsNegativeBalance(): bool
    {
        $policy = (string) ($this->app->get(ConfigurationEnum::PARKING_INSUFFICIENT_FUNDS_POLICY->value) ?? 'deny');

        return $policy === 'allow_negative';
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
        ?string $transactionId = null,
        ?float $amountCharged = null,
    ): array {
        return [
            'success' => $success,
            'message' => $message,
            'code' => $code->value,
            'order_id' => null,
            'transaction_id' => $transactionId,
            'amount_charged' => $amountCharged,
        ];
    }
}
