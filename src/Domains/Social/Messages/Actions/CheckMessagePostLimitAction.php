<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Carbon\Carbon;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class CheckMessagePostLimitAction
{
    protected Users $user;
    protected Apps $app;

    public function __construct(
        public Message $message,
        public int $timeFrame = 24,
        public ?int $messageTypeId = null,
        public bool $getChildrenCount = true,
        public ?array $messageJsonFilters = null
    ) {
        //why? dont know but the model cache causes issues
        $this->user = Users::getById($this->message->users_id);
        $this->app = Apps::getById($message->apps_id);
    }

    /**
     * execute
     */
    public function execute(): void
    {
        $modelIndex = $this->message->message['ai_model']['value'] ?? null;
        $orderCredit = $this->user->get('order_credits', []);
        $aiIndex = match ($this->message->message['type'] ?? '') {
            'video-format' => 'video',
            'image-format' => 'image',
            default => 'image',
        };

        if (isset($orderCredit[$aiIndex][$modelIndex]) && $orderCredit[$aiIndex][$modelIndex] > 0) {
            return ;
        }

        $messageCount = Message::getUserMessageCountInTimeFrameBuilder(
            $this->message->user->getId(),
            $this->message->app,
            $this->timeFrame,
            $this->messageTypeId,
            $this->getChildrenCount,
            $this->messageJsonFilters
        );

        //exclude message type
        if ($this->message->app->get('exclude-message-type-from-limit')) {
            $messageCount->whereNotIn('message_types_id', $this->message->app->get('exclude-message-type-from-limit'));
        }

        $messageCount = $messageCount->count();

        /**
         * @todo for now until the refactor
         * update limit by orders
         */
        $totalOrdersToday = Order::fromApp($this->message->app)
                               ->where('users_id', $this->message->user->getId())
                               ->where('created_at', '>=', Carbon::now()->subHours($this->timeFrame))
                               ->count();

        $messageCount -= (int) $totalOrdersToday;

        $messageLimit = $this->app->get('message-post-limit');
        $this->user->set('composer_ideas_used', $messageCount, true);

        if ($messageCount > $messageLimit) {
            throw new Exception('Your daily limit has been reached.');
        }
    }
}
