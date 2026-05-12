<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Notifications;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Transaction;
use Kanvas\Users\Models\Users;

class WalletRefundConfirmedNotification extends Notification
{
    public function __construct(
        protected Order $order,
        protected Transaction $transaction,
        protected Apps $app,
        protected Companies $company,
        protected ?Users $fromUser = null,
    ) {
        parent::__construct($order, [
            'app' => $app,
            'company' => $company,
            'fromUser' => $fromUser,
        ]);
        $this->setType('blank');
        $this->setTemplateName('wallet-refund-confirmed');
        $this->setData([
            'order' => $order,
            'transaction' => $transaction,
            'amount' => $transaction->amountFloat ?? 0,
        ]);
        $this->channels = ['mail', 'push'];
    }
}
