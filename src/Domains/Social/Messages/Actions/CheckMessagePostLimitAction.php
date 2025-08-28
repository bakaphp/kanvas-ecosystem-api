<?php

declare(strict_types=1);

namespace Kanvas\Social\Messages\Actions;

use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;
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
     *
     * @return void
     */
    public function execute()
    {
        //Log::info("Checking with message type $this->messageTypeId");
        $messageCount = Message::getUserMessageCountInTimeFrame(
            $this->message->user->getId(),
            $this->message->app,
            $this->timeFrame,
            $this->messageTypeId,
            $this->getChildrenCount,
            $this->messageJsonFilters
        );

        /**
         * @todo for now until the refactor
         * update limit by orders
         */
        $totalOrdersToday = Order::fromApp($this->message->app)
                                ->where('users_id', $this->message->user->getId())
                                ->where('created_at', '>=', Carbon::now()->subHours($this->timeFrame))
                                ->count();

        $messageCount += (int) $totalOrdersToday;

        // $this->message->app->reGenerateRedisSettings();
        //$messageLimit = $this->message->app->get('message-post-limit');
        //Log:info("Message Count for today: $messageCount of $messageLimit");

        if ($messageCount >= $this->message->app->get('message-post-limit')) {
            throw new Exception('Your daily limit has been reached.');
        }
    }
}
