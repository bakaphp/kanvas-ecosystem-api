<?php

declare(strict_types=1);

namespace Kanvas\Souk\Payments\Actions;

use Baka\Support\IPInfo;
use Kanvas\Auth\Services\AuthenticationService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class EnforceCardVelocityLimitAction
{
    private const int WINDOW_HOURS = 24;

    public function __construct(
        protected Payments $payment
    ) {
    }

    public function execute(): void
    {
        $orderType = $this->payment->order?->orderType;
        $card = $this->cardKey($this->payment->payment_method_brand, $this->payment->payment_method_last_four);

        if (! $orderType || $card === null) {
            return;
        }

        $isCorporate = filter_var($this->payment->company?->get('is_corporate'), FILTER_VALIDATE_BOOLEAN);
        $maxCards = $orderType->cardVelocityLimit($isCorporate ? 'corporate_max_cards_daily' : 'max_cards_daily');
        $banCards = $orderType->cardVelocityLimit($isCorporate ? 'corporate_ban_cards_daily' : 'ban_cards_daily');

        if ($maxCards <= 0 && $banCards <= 0) {
            return;
        }

        $recentCards = $this->recentCards($orderType);

        if (in_array($card, $recentCards, true)) {
            return;
        }

        $cards = count($recentCards) + 1;
        $shouldBan = $banCards > 0 && $cards >= $banCards;

        if (! $shouldBan && ($maxCards <= 0 || $cards < $maxCards)) {
            return;
        }

        $this->reject($orderType, $cards, $shouldBan);
    }

    private function recentCards(OrderTypes $orderType): array
    {
        return Payments::query()
            ->where('apps_id', $this->payment->apps_id)
            ->where('users_id', $this->payment->users_id)
            ->where('id', '!=', $this->payment->getId())
            ->where('created_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->where('payable_type', Order::class)
            ->whereIn('payable_id', Order::query()->select('id')->where('order_types_id', $orderType->getId()))
            ->get(['payment_method_brand', 'payment_method_last_four'])
            ->map(fn (Payments $payment) => $this->cardKey($payment->payment_method_brand, $payment->payment_method_last_four))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function cardKey(?string $brand, ?string $lastFour): ?string
    {
        if (! $brand || ! $lastFour) {
            return null;
        }

        return strtolower($brand) . ':' . $lastFour;
    }

    private function reject(OrderTypes $orderType, int $cards, bool $ban): void
    {
        $this->payment->status = PaymentStatusEnum::FAILED->value;
        $this->payment->saveOrFail();
        $this->payment->addLog('payment_card_velocity_blocked', [
            'order_type' => $orderType->name,
            'cards' => $cards,
            'banned' => $ban,
        ]);

        if ($ban) {
            $this->banUser();
        }

        report(new TooManyRequestsHttpException(
            message: "Card velocity exceeded - order_type:{$orderType->name} user:{$this->payment->users_id} cards:{$cards} banned:"
                . ($ban ? '1' : '0') . ' ip:' . IPInfo::getClientIp() . " app:{$this->payment->apps_id}"
        ));

        throw new ValidationException(__('payment_errors.card_velocity_exceeded', [], 'es'));
    }

    private function banUser(): void
    {
        $user = $this->payment->user;
        $app = $this->payment->app;
        $profile = $user->getAppProfile($app);
        $profile->banned = 1;
        $profile->saveOrFail();

        AuthenticationService::logoutFromAllDevices($user, $app);
    }
}
