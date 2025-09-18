<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PromptMine\Actions;

use Kanvas\Inventory\Variants\Models\Variants;
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
        $modelIndex = $this->message->message['ai_model']['value'] ?? null;
        //$aiModelKey = $variant->getAttributeBySlug('ai-model')?->value;

        if ($modelIndex === null) {
            return [];
        }

        $variant = Variants::searchByAttributeValue($this->message->app, 'ai-model', $modelIndex)->first();
        $relatedModelIndex = $variant?->getAttributeBySlug('ai-model-related')?->value ?? null;

        $orderCredit = $this->user->get('order_credits', []);

        if (isset($orderCredit[$aiIndex][$modelIndex]) && $orderCredit[$aiIndex][$modelIndex] > 0) {
            $orderCredit[$aiIndex][$modelIndex] -= 1;

            if ($relatedModelIndex !== null
                && $relatedModelIndex !== $modelIndex
                && isset($orderCredit[$aiIndex][$relatedModelIndex])
                && $orderCredit[$aiIndex][$relatedModelIndex] > 0) {
                $orderCredit[$aiIndex][$relatedModelIndex] -= 1;
                if ($orderCredit[$aiIndex][$relatedModelIndex] <= 0) {
                    unset($orderCredit[$aiIndex][$relatedModelIndex]);
                }
            }

            if ($orderCredit[$aiIndex][$modelIndex] <= 0) {
                unset($orderCredit[$aiIndex][$modelIndex]);
            }

            $this->user->set('order_credits', $orderCredit, true);
        }

        return $orderCredit;
    }
}
