<?php

declare(strict_types=1);

namespace Kanvas\Souk\Wallet\Notifications;

use Illuminate\Mail\Mailable;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Notifications\Notification;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Wallet\Transaction;
use Kanvas\Users\Models\Users;
use Override;
use Throwable;

class WalletRechargeConfirmedNotification extends Notification
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
        $this->setTemplateName('wallet-recharge-confirmed');
        $this->setData([
            'order' => $order,
            'transaction' => $transaction,
            'amount' => $transaction->amountFloat ?? 0,
        ]);
        $this->channels = ['mail', 'push'];
    }

    #[Override]
    public function toMail(object $notifiable): Mailable
    {
        $mail = parent::toMail($notifiable);

        try {
            $receiptFile = $this->order
                ->files()
                ->where('filesystem.file_type', 'pdf')
                ->where('filesystem_entities.field_name', $this->order->order_number . '_recharge')
                ->latest('filesystem_entities.id')
                ->first();

            if ($receiptFile !== null) {
                $pdfContents = @file_get_contents($receiptFile->url);

                if ($pdfContents !== false) {
                    $filename = $receiptFile->name ?: ($this->order->order_number . '_recharge.pdf');
                    $mail->attachData($pdfContents, $filename, ['mime' => 'application/pdf']);
                } else {
                    report('WalletRechargeConfirmedNotification: could not download receipt PDF for order ' . $this->order->getId());
                }
            }
        } catch (Throwable $e) {
            report($e);
        }

        return $mail;
    }
}
