<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Social\Messages\Models\Message;
use Kanvas\Users\Models\Users;

class MessageOrderFulfillmentAction
{
    protected Users $user;

    public function __construct(protected Message $message)
    {
        //why? dont know but the model cache causes issues
        $this->user = Users::getById($this->message->users_id);
    }

    public function execute(string $aiIndex): array
    {
        // Deduct user credit based on the selected video filter
        $modelIndex = $this->message->message['ai_model']['value'];
        $orderCredit = $this->user->get('order_credits', []);

        if (isset($orderCredit[$aiIndex][$modelIndex]) && $orderCredit[$aiIndex][$modelIndex] > 0) {
            $orderCredit[$aiIndex][$modelIndex] -= 1;

            if ($orderCredit[$aiIndex][$modelIndex] == 0) {
                unset($orderCredit[$aiIndex][$modelIndex]);
            }

            $this->user->set('order_credits', $orderCredit, true);
        }

        return $orderCredit;
    }
}
