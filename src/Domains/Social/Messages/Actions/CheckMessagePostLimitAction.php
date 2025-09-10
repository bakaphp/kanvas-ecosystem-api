<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Carbon\Carbon;
use Exception;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class CheckMessagePostLimitAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        public Message $message,
        public int $timeFrame = 24,
        public ?int $messageTypeId = null,
        public bool $getChildrenCount = false,
        public ?array $messageJsonFilters = null
    ) {
    }

    /**
     * execute
     */
    public function execute(): void
    {
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

        // $this->message->app->reGenerateRedisSettings();
        $messageLimit = $this->message->app->get('message-post-limit');
        $this->message->user->set('composer_ideas_used', $messageCount);
        if ($messageCount > $messageLimit) {
            throw new Exception('Your daily limit has been reached.');
        }
    }
}
